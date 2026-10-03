<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Client minimal pour l'API Twilio Verify (envoi et validation des OTP). */
class TwilioVerifyClient
{
    /** Codes Twilio d'un refus passager : un nouvel essai, quelques instants plus tard, peut aboutir. */
    private const TRANSIENT_CODES = [
        20429, // trop de requetes simultanees
        21608, // restriction de compte encore active juste apres l'approbation du profil (propagation chez Twilio)
    ];

    public function sendVerification(string $phone): void
    {
        $data = [
            'To' => $phone,
            'Channel' => 'sms',
            // SMS en francais.
            'Locale' => 'fr',
        ];
        // Modele de message qui affiche le nom de l'application (sans lui, Twilio peut envoyer
        // un SMS generique « Votre code de vérification est : ... », moins rassurant).
        $template = config('services.sms.twilio.template_sid');
        if (is_string($template) && preg_match('/^HJ[a-f0-9]{32}$/i', $template)) {
            $data['TemplateSid'] = $template;
        }

        // Un envoi qui echoue pour une raison passagere (reseau, panne Twilio, 21608 juste apres
        // l'approbation du profil) est retente une fois : le membre n'a pas a recommencer.
        // Twilio Verify reutilise la verification en attente : pas de second code different.
        try {
            $this->post('Verifications', $data);
        } catch (TwilioVerifyException $e) {
            if (! $e->isTransient()) {
                throw $e;
            }
            usleep(max(0, (int) config('services.sms.twilio.retry_delay_ms', 1500)) * 1000);
            $this->post('Verifications', $data);
        }
    }

    public function checkVerification(string $phone, string $code): bool
    {
        $response = $this->post('VerificationCheck', [
            'To' => $phone,
            'Code' => $code,
        ], allowRejectedCode: true);

        return $response->successful() && $response->json('status') === 'approved';
    }

    /**
     * Service Verify configure (nom affiche dans le SMS, longueur du code) : sert a verifier les
     * identifiants sans envoyer de SMS (php artisan app:sms-check).
     *
     * @return array{template_sid: string|null, friendly_name: string|null, code_length: int|null}
     */
    public function service(): array
    {
        try {
            $response = $this->http()->get($this->baseUrl());
        } catch (ConnectionException $e) {
            throw new TwilioVerifyException('Twilio Verify is unreachable: '.$e->getMessage());
        }
        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return [
            'template_sid' => config('services.sms.twilio.template_sid') ?: null,
            'friendly_name' => $response->json('friendly_name'),
            'code_length' => is_numeric($response->json('code_length')) ? (int) $response->json('code_length') : null,
        ];
    }

    private function post(string $resource, array $data, bool $allowRejectedCode = false): Response
    {
        try {
            $response = $this->http()->asForm()->post($this->baseUrl().'/'.$resource, $data);
        } catch (ConnectionException $e) {
            throw new TwilioVerifyException('Twilio Verify is unreachable: '.$e->getMessage(), transient: true);
        }

        if ($response->successful()) {
            return $response;
        }

        // Code errone, verification expiree ou trop d'essais : ce n'est pas une panne, le code est refuse.
        $twilioCode = (int) $response->json('code');
        if ($allowRejectedCode && (
            ($response->status() === 404 && $twilioCode === 20404)
            || ($response->status() === 429 && $twilioCode === 60202)
        )) {
            // Code refuse : le service a bien repondu.
            return $response;
        }

        throw $this->failure($response);
    }

    private function failure(Response $response): TwilioVerifyException
    {
        $twilioCode = is_numeric($response->json('code')) ? (int) $response->json('code') : null;

        return new TwilioVerifyException(
            "Twilio Verify request failed (HTTP {$response->status()})".($twilioCode ? " (Twilio code {$twilioCode})" : '').'.',
            $response->status(), $twilioCode,
            transient: $response->serverError() || in_array($twilioCode, self::TRANSIENT_CODES, true));
    }

    private function baseUrl(): string
    {
        $serviceSid = config('services.sms.twilio.verify_service_sid');
        if (! is_string($serviceSid) || ! preg_match('/^VA[a-f0-9]{32}$/i', $serviceSid)) {
            throw new TwilioVerifyException('Twilio Verify is not configured correctly (TWILIO_VERIFY_SERVICE_SID).');
        }

        return "https://verify.twilio.com/v2/Services/{$serviceSid}";
    }

    /** Requete authentifiee : cle API (recommande) ou, a defaut, jeton du compte. */
    private function http(): PendingRequest
    {
        $accountSid = config('services.sms.twilio.sid');
        $authToken = config('services.sms.twilio.token');
        $apiKeySid = config('services.sms.twilio.api_key_sid');
        $apiKeySecret = config('services.sms.twilio.api_key_secret');

        if (! is_string($accountSid) || ! preg_match('/^AC[a-f0-9]{32}$/i', $accountSid)) {
            throw new TwilioVerifyException('Twilio Verify is not configured correctly (TWILIO_ACCOUNT_SID).');
        }

        $hasApiKeySid = is_string($apiKeySid) && $apiKeySid !== '';
        $hasApiKeySecret = is_string($apiKeySecret) && $apiKeySecret !== '';
        if ($hasApiKeySid !== $hasApiKeySecret
            || ($hasApiKeySid && ! preg_match('/^SK[a-f0-9]{32}$/i', $apiKeySid))) {
            throw new TwilioVerifyException('Twilio Verify API key credentials are incomplete (TWILIO_API_KEY_SID / TWILIO_API_KEY_SECRET).');
        }

        $username = $hasApiKeySid ? $apiKeySid : $accountSid;
        $password = $hasApiKeySid ? $apiKeySecret : $authToken;
        if (! is_string($password) || $password === '') {
            throw new TwilioVerifyException('Twilio Verify credentials are not configured.');
        }

        return Http::acceptJson()->withBasicAuth($username, $password)->connectTimeout(5)->timeout(15);
    }
}
