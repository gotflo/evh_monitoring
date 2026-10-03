<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Localite approximative d'une adresse IP (ville, region, pays), pour savoir d'ou chaque personne
 * se connecte a la plateforme. Resultats en cache 30 jours (table ip_locations) ; les adresses
 * privees (reseau local) ne sont jamais envoyees au service.
 *
 * Services possibles (GEOIP_PROVIDER) :
 *  - ipwhois : https://ipwho.is (HTTPS, sans cle, noms en francais) - par defaut ;
 *  - ipapi   : http://ip-api.com (sans cle, usage non commercial, HTTP) ;
 *  - none    : localisation desactivee.
 * La localisation par IP est approximative (souvent la ville du fournisseur d'acces).
 */
class GeoLocator
{
    public const TTL_DAYS = 30;

    public static function provider(): string
    {
        return (string) config('monitoring.geo.provider', 'ipwhois');
    }

    public static function enabled(): bool
    {
        return in_array(self::provider(), ['ipwhois', 'ipapi'], true);
    }

    public static function isPublic(?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Localites connues pour ces adresses (sans appel au service).
     *
     * @param  array<int, string|null>  $ips
     * @return array<string, array<string, mixed>> ip => localite
     */
    public static function lookup(array $ips): array
    {
        $ips = array_values(array_unique(array_filter($ips)));
        $out = [];
        if (! $ips) {
            return $out;
        }
        $rows = DB::table('ip_locations')->whereIn('ip', $ips)->get()->keyBy('ip');
        foreach ($ips as $ip) {
            $row = $rows[$ip] ?? null;
            $out[$ip] = match (true) {
                ! self::isPublic($ip) => ['status' => 'private', 'label' => 'Réseau local', 'country_code' => null],
                $row === null => ['status' => 'pending', 'label' => self::enabled() ? 'Localisation en cours' : 'Localisation désactivée', 'country_code' => null],
                default => ['status' => $row->status, 'label' => self::label($row), 'city' => $row->city, 'region' => $row->region,
                    'country' => $row->country, 'country_code' => $row->country_code],
            };
        }

        return $out;
    }

    public static function label(object $row): string
    {
        if ($row->status !== 'ok') {
            return $row->status === 'private' ? 'Réseau local' : 'Localité inconnue';
        }
        $parts = array_values(array_unique(array_filter([$row->city, $row->region, $row->country])));

        return $parts ? implode(', ', $parts) : 'Localité inconnue';
    }

    /** Localise une adresse (cache, sinon service) et l'enregistre. */
    public static function locate(string $ip): ?object
    {
        $cached = DB::table('ip_locations')->where('ip', $ip)->first();
        if ($cached && $cached->status !== 'error' && $cached->resolved_at > now()->subDays(self::TTL_DAYS)) {
            return $cached;
        }
        $data = ['status' => 'unknown', 'city' => null, 'region' => null, 'country' => null, 'country_code' => null, 'source' => self::provider()];
        if (! self::isPublic($ip)) {
            $data['status'] = 'private';
        } elseif (self::enabled()) {
            try {
                $data = array_merge($data, self::query($ip));
            } catch (\Throwable) {
                $data['status'] = 'error';
            }
        }
        DB::table('ip_locations')->updateOrInsert(['ip' => $ip], $data + ['resolved_at' => now()]);

        return DB::table('ip_locations')->where('ip', $ip)->first();
    }

    /** @return array<string, mixed> */
    private static function query(string $ip): array
    {
        if (self::provider() === 'ipapi') {
            $r = Http::timeout(5)->acceptJson()->get('http://ip-api.com/json/'.rawurlencode($ip), ['fields' => 'status,country,countryCode,regionName,city', 'lang' => 'fr']);
            if (! $r->successful() || $r->json('status') !== 'success') {
                return ['status' => $r->successful() ? 'unknown' : 'error'];
            }

            return ['status' => 'ok', 'city' => $r->json('city'), 'region' => $r->json('regionName'), 'country' => $r->json('country'),
                'country_code' => strtoupper((string) $r->json('countryCode')) ?: null];
        }

        $r = Http::timeout(5)->acceptJson()->get('https://ipwho.is/'.rawurlencode($ip), ['lang' => 'fr', 'fields' => 'success,country,country_code,region,city']);
        if (! $r->successful() || $r->json('success') !== true) {
            return ['status' => $r->successful() ? 'unknown' : 'error'];
        }

        return ['status' => 'ok', 'city' => $r->json('city'), 'region' => $r->json('region'), 'country' => $r->json('country'),
            'country_code' => strtoupper((string) $r->json('country_code')) ?: null];
    }

    /**
     * Localise les adresses de connexion recentes qui ne le sont pas encore (appele a chaque
     * verification, nombre borne pour respecter les limites du service).
     */
    public static function resolvePending(int $limit = 30): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $ips = Metrics::app()->table('monitor_events')->whereIn('type', ['auth.login', 'auth.otp_failed', 'auth.otp_requested'])
            ->whereNotNull('ip')->where('created_at', '>=', now()->subDays(self::TTL_DAYS))
            ->orderByDesc('id')->limit(2000)->pluck('ip')->unique()
            ->merge(DB::table('audit_logs')->whereNotNull('ip')->where('created_at', '>=', now()->subDays(self::TTL_DAYS))->pluck('ip'))
            ->unique()->values();
        $known = DB::table('ip_locations')->whereIn('ip', $ips)->where('status', '!=', 'error')
            ->where('resolved_at', '>', now()->subDays(self::TTL_DAYS))->pluck('ip')->flip();

        $n = 0;
        foreach ($ips as $ip) {
            if ($n >= $limit) {
                break;
            }
            if (! isset($known[$ip])) {
                self::locate($ip);
                $n++;
            }
        }

        return $n;
    }

    /** Pays consideres comme habituels (MONITOR_HOME_COUNTRIES, codes ISO separes par des virgules). @return array<int, string> */
    public static function homeCountries(): array
    {
        return array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', (string) config('monitoring.geo.home_countries', 'CA')))));
    }
}
