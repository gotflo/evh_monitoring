<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Validation et formatage des numeros de telephone via Google libphonenumber.
 * Verifie que le numero est reellement valide pour le pays, et le met au
 * format international E.164 (ex. +14181234567). Rejette les numeros farfelus.
 */
class Phone
{
    /**
     * Retourne le numero au format E.164 s'il est valide, sinon null.
     *
     * @param  string  $input   Numero saisi (national ou international)
     * @param  string  $region  Code pays ISO (ex. CA, FR) si le numero est national
     */
    public static function normalize(string $input, string $region = 'CA'): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            // Si $input commence par +, la region est ignoree (deduite de l'indicatif).
            $proto = $util->parse($input, strtoupper($region));
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumber($proto)) {
            return null;
        }

        return $util->format($proto, PhoneNumberFormat::E164);
    }
}
