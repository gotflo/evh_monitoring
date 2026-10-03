<?php

namespace App\Services;

use RuntimeException;

/** Echec d'un appel a l'API de la plateforme (injoignable, signature refusee, action refusee). */
class AgentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus, public readonly bool $configuration = false)
    {
        parent::__construct($message);
    }

    /** Statut a renvoyer a l'ecran : erreur de validation telle quelle, sinon service indisponible. */
    public function status(): int
    {
        return in_array($this->httpStatus, [404, 409, 422], true) ? $this->httpStatus : 503;
    }
}
