<?php

namespace App\Services\Sms;

use RuntimeException;

/**
 * Echec d'un appel a Twilio Verify. Le message technique (anglais) va dans les journaux ;
 * userMessage() donne le texte a afficher au fidele, sans detail technique.
 */
class TwilioVerifyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $twilioCode = null,
        private readonly bool $transient = false,
    ) {
        parent::__construct($message);
    }

    /** Echec passager (reseau, panne Twilio, restriction en cours de levee) : un nouvel essai peut aboutir. */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    /**
     * Le probleme vient-il du numero saisi (et non du service ou de sa configuration) ?
     * 21608 (compte encore restreint) n'en fait pas partie : c'est le service qui n'est pas pret.
     */
    public function isAboutThePhone(): bool
    {
        return in_array($this->twilioCode, [60200, 60203, 60205, 60212, 60410, 60605, 21211, 21408, 21614], true);
    }

    public function userMessage(): string
    {
        return match ($this->twilioCode) {
            60200, 21211 => "Ce numéro de téléphone n'est pas valide.",
            60203, 60212 => 'Trop de codes ont été demandés pour ce numéro. Patientez 10 minutes puis réessayez.',
            60205, 21614 => 'Ce numéro ne peut pas recevoir de SMS (ligne fixe). Utilisez un numéro de cellulaire.',
            60410, 60605, 21408 => "L'envoi de SMS vers ce pays n'est pas encore activé. Contactez un responsable.",
            default => "L'envoi du code par SMS est momentanément indisponible. Réessayez dans quelques minutes.",
        };
    }
}
