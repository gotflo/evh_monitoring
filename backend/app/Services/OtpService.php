<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\TwilioVerifyClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Codes de connexion a la console : Twilio Verify en production ; en developpement (mode « log »),
 * code genere localement, stocke hache, ecrit dans le journal et affiche a l'ecran en local.
 */
class OtpService
{
    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SEC = 45;

    public function __construct(private TwilioVerifyClient $twilioVerify) {}

    private function usesTwilio(): bool
    {
        return config('services.sms.driver') === 'twilio_verify';
    }

    /** Retourne le code uniquement en mode « log » (developpement). */
    public function sendCode(string $phone): ?string
    {
        $code = $this->usesTwilio() ? null : str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $otp = OtpCode::create([
            'phone' => $phone,
            // Avec Twilio, le vrai code n'est jamais connu ici : empreinte aleatoire.
            'code_hash' => Hash::make($code ?? bin2hex(random_bytes(32))),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            if ($this->usesTwilio()) {
                $this->twilioVerify->sendVerification($phone);
            } else {
                Log::info('[SMS simule] code de connexion a la console envoye vers ...'.substr($phone, -4));
            }
        } catch (Throwable $e) {
            $otp->delete();
            throw $e;
        }

        return $code;
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())->latest()->first();
        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $valid = $this->usesTwilio()
            ? $this->twilioVerify->checkVerification($phone, $code)
            : Hash::check($code, $otp->code_hash);
        if (! $valid) {
            $otp->increment('attempts');

            return false;
        }
        $otp->update(['consumed_at' => Carbon::now()]);

        return true;
    }

    public function isThrottled(string $phone): bool
    {
        $last = OtpCode::where('phone', $phone)->latest()->first();

        return $last && $last->created_at->diffInSeconds(Carbon::now()) < self::RESEND_COOLDOWN_SEC;
    }

    public static function prune(): void
    {
        OtpCode::where('created_at', '<', now()->subDays(7))->delete();
    }
}
