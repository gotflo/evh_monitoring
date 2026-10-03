<?php

namespace App\Http\Controllers\Api;

use App\Models\Operator;
use App\Services\OtpService;
use App\Services\Sms\TwilioVerifyException;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Connexion a la console : numero autorise + code a usage unique (Twilio Verify en production).
 * Un numero non autorise ne recoit aucun SMS (aucun cout, aucun abus) et la reponse est
 * identique : on ne revele pas quels numeros ont acces. Session limitee (CONSOLE_SESSION_HOURS).
 */
class AuthController extends ConsoleController
{
    private const GENERIC_SENT = 'Si ce numéro est autorisé, un code de connexion vient de lui être envoyé par SMS.';

    public function __construct(private OtpService $otp) {}

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);
        $phone = Phone::normalize($data['phone'], $data['country'] ?? 'CA');
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro de téléphone n\'est pas valide.']);
        }

        $operator = Operator::where('phone', $phone)->first();
        if (! $operator || ! $operator->isAllowed()) {
            Audit::log(null, 'console.login_denied', 'denied', 'phone', null, Operator::maskPhone($phone),
                ['reason' => $operator ? 'accès retiré' : 'numéro non autorisé']);

            return response()->json(['message' => self::GENERIC_SENT, 'phone' => $phone]);
        }

        if ($this->otp->isThrottled($phone)) {
            throw ValidationException::withMessages(['phone' => 'Un code vient déjà d\'être envoyé. Patientez un instant avant de réessayer.']);
        }

        try {
            $code = $this->otp->sendCode($phone);
        } catch (TwilioVerifyException $e) {
            Log::error('Console : envoi du code Twilio Verify impossible', ['http' => $e->httpStatus, 'twilio_code' => $e->twilioCode, 'error' => $e->getMessage()]);
            abort(503, $e->userMessage());
        }

        $payload = ['message' => self::GENERIC_SENT, 'phone' => $phone];
        if ($code !== null && (app()->environment('local') || config('app.expose_otp'))) {
            $payload['dev_code'] = $code;
        }

        return response()->json($payload);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $phone = Phone::normalize($data['phone']);
        $operator = $phone ? Operator::where('phone', $phone)->first() : null;
        $invalid = fn () => ValidationException::withMessages(['code' => 'Code invalide ou expiré.']);

        if (! $operator || ! $operator->isAllowed()) {
            Audit::log(null, 'console.login_denied', 'denied', 'phone', null, Operator::maskPhone($phone), ['step' => 'code']);
            throw $invalid();
        }

        try {
            $valid = $this->otp->verify($phone, $data['code']);
        } catch (TwilioVerifyException $e) {
            Log::error('Console : vérification Twilio Verify impossible', ['http' => $e->httpStatus, 'twilio_code' => $e->twilioCode, 'error' => $e->getMessage()]);
            abort(503, 'La vérification du code est momentanément indisponible. Réessayez dans quelques minutes.');
        }
        if (! $valid) {
            Audit::log($operator, 'console.login_failed', 'failure', 'operator', $operator->id, $operator->label());
            throw $invalid();
        }

        $operator->forceFill(['status' => 'active', 'activated_at' => $operator->activated_at ?? now(), 'last_login_at' => now()])->save();
        $hours = max(1, min(72, (int) config('monitoring.console.session_hours', 12)));
        $newToken = $operator->createToken('console', ['console'], now()->addHours($hours));
        $operator->withAccessToken($newToken->accessToken);
        Audit::log($operator, 'console.login', 'success', 'operator', $operator->id, $operator->label(), ['session_hours' => $hours]);

        return response()->json(['token' => $newToken->plainTextToken, 'expires_in_hours' => $hours] + $this->payload($operator));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->operator($request)));
    }

    public function logout(Request $request): JsonResponse
    {
        $operator = $this->operator($request);
        $operator->currentAccessToken()?->delete();
        Audit::log($operator, 'console.logout', 'success', 'operator', $operator->id, $operator->label());

        return response()->json(['message' => 'Déconnecté de la console.']);
    }

    /** @return array<string, mixed> */
    private function payload(Operator $operator): array
    {
        $token = $operator->currentAccessToken();

        return [
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'label' => $operator->label(),
                'phone' => $operator->phone,
                'role' => $operator->role,
                'role_label' => Operator::ROLE_LABELS[$operator->role] ?? $operator->role,
                'is_primary' => $operator->is_primary,
                'session_expires_at' => $token && isset($token->expires_at) ? $token->expires_at?->toIso8601String() : null,
            ],
            'abilities' => $operator->abilities(),
            'platform' => ['url' => config('monitoring.platform.url')],
        ];
    }
}
