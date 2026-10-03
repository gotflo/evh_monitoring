<?php

namespace App\Support;

/**
 * Masquage avant tout enregistrement ou affichage dans la console : numeros de telephone,
 * codes de connexion, jetons, cles et mots de passe, valeurs des requetes SQL, parametres
 * d'URL. Volontairement large : mieux vaut masquer un nombre anodin qu'exposer un code.
 */
class Redactor
{
    public const MASK = '[masqué]';

    /** Cles dont la valeur n'est jamais conservee. */
    private const SENSITIVE_KEYS = '/pass(word)?|secret|token|authori[sz]ation|cookie|api[_-]?key|private[_-]?key|otp|^code$|code_hash|signature|session|credential|p256dh|auth$|endpoint/i';

    public static function text(?string $text, int $max = 2000): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $t = $text;

        // Requetes SQL : les valeurs (donnees des membres) sont retirees, la structure reste.
        if (str_contains($t, 'SQL:') || preg_match('/\b(select|insert|update|delete)\b.+\b(from|into|set)\b/is', $t)) {
            $t = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '?', $t) ?? $t;
            $t = preg_replace('/"(?:[^"\\\\]|\\\\.)*"(?=\s*[,)])/s', '?', $t) ?? $t;
        }

        $t = preg_replace('/Bearer\s+[A-Za-z0-9|._~+\/=-]+/i', 'Bearer '.self::MASK, $t) ?? $t;
        // Jeton Sanctum (id|secret) et longues chaines aleatoires (cles, signatures).
        $t = preg_replace('/\b\d+\|[A-Za-z0-9]{20,}\b/', self::MASK, $t) ?? $t;
        // Identifiants Twilio : prefixe et 4 derniers caracteres seulement.
        $t = preg_replace_callback('/\b(AC|SK|VA|HJ|PN|MG)[a-f0-9]{32}\b/i', fn ($m) => $m[1].'...'.substr($m[0], -4), $t) ?? $t;
        $t = preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', self::MASK, $t) ?? $t;
        // Abonnement au calendrier : le jeton fait partie du chemin.
        $t = preg_replace('#(/calendar/feed/)[^/\s"\']+#i', '$1'.self::MASK, $t) ?? $t;
        // Parametres d'URL : retires.
        $t = preg_replace('#((?:https?://|/)[^\s?"\']*)\?[^\s"\']*#i', '$1?...', $t) ?? $t;
        // cle=valeur sensibles (password=..., "token":"...").
        $t = preg_replace('/((?:pass(?:word)?|secret|token|api[_-]?key|code|otp|authorization)["\']?\s*[:=]\s*["\']?)[^\s"\',&}]+/i', '$1'.self::MASK, $t) ?? $t;
        // Numeros de telephone : 4 derniers chiffres seulement.
        $last4 = fn ($m) => '...'.substr(preg_replace('/\D/', '', $m[0]), -4);
        $t = preg_replace_callback('/\+\d{8,15}\b/', $last4, $t) ?? $t;
        $t = preg_replace_callback('/(?<![\d.:\/-])(?:\(\d{3}\)\s?|\b\d{3}[ .-])\d{3}[ .-]\d{4}\b/', $last4, $t) ?? $t;
        $t = preg_replace_callback('/(?<![\d.:\/-])\d{10,11}(?![\d.:\/-])/', $last4, $t) ?? $t;
        // Codes a 6 chiffres (codes de connexion).
        $t = preg_replace('/(?<![\d.:\/-])\d{6}(?![\d.:\/-])/', '******', $t) ?? $t;
        // Courriels : initiale + domaine.
        $t = preg_replace_callback('/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', fn ($m) => $m[1].'...@'.$m[2], $t) ?? $t;

        return mb_substr($t, 0, $max);
    }

    /**
     * Contexte d'un evenement : valeurs sensibles retirees, objets resumes, taille bornee.
     *
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function context(array $context, int $depth = 0): array
    {
        $out = [];
        $i = 0;
        foreach ($context as $key => $value) {
            if (++$i > 40) {
                $out['...'] = 'contexte tronqué';
                break;
            }
            if (is_string($key) && preg_match(self::SENSITIVE_KEYS, $key)) {
                $out[$key] = self::MASK;

                continue;
            }
            $out[$key] = match (true) {
                $value === null, is_bool($value), is_int($value), is_float($value) => $value,
                is_string($value) => self::text($value, 500),
                is_array($value) => $depth >= 3 ? '[...]' : self::context($value, $depth + 1),
                $value instanceof \Throwable => get_class($value).' : '.self::text($value->getMessage(), 300),
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \Stringable => self::text((string) $value, 300),
                is_object($value) => '['.class_basename($value).']',
                default => '['.gettype($value).']',
            };
        }

        return $out;
    }

    /** Chemin de fichier relatif au projet (jamais le chemin complet du serveur). */
    public static function path(?string $file): ?string
    {
        if (! $file) {
            return null;
        }
        $base = str_replace('\\', '/', base_path()).'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);
    }

    /**
     * Extrait utile de la pile d'appels : fichiers de l'application d'abord, sans arguments.
     *
     * @return array<int, string>
     */
    public static function trace(\Throwable $e, int $max = 12): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }
            $path = self::path($frame['file']);
            $call = isset($frame['class']) ? class_basename($frame['class']).($frame['type'] ?? '::').($frame['function'] ?? '') : ($frame['function'] ?? '');
            $frames[] = $path.':'.($frame['line'] ?? '?').($call ? ' '.$call.'()' : '');
        }
        $app = array_values(array_filter($frames, fn ($f) => ! str_starts_with($f, 'vendor/')));

        return array_slice($app ?: $frames, 0, $max);
    }

    /** Empreinte stable d'une erreur (chiffres et valeurs variables neutralises). */
    public static function fingerprint(string ...$parts): string
    {
        $normalized = array_map(fn ($p) => preg_replace(['/\d+/', "/'[^']*'/", '/"[^"]*"/'], ['#', '?', '?'], mb_strtolower($p)), $parts);

        return sha1(implode('|', $normalized));
    }
}
