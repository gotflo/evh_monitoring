<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Recherche « contient » sure : le texte saisi est toujours transmis en parametre lie
 * (aucune injection SQL possible) et ses caracteres % et _ sont neutralises, pour qu'ils
 * soient cherches tels quels au lieu de servir de jokers. Le caractere d'echappement « ! »
 * se comporte de la meme facon sous MySQL et SQLite.
 */
class Like
{
    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @param  Builder|EloquentBuilder  $query
     * @param  string  $column  nom de colonne ecrit dans le code (jamais issu de la requete)
     */
    public static function where($query, string $column, string $pattern, string $boolean = 'and'): void
    {
        $query->whereRaw($column.' LIKE ? ESCAPE \'!\'', [$pattern], $boolean);
    }

    /** @param  Builder|EloquentBuilder  $query */
    public static function contains($query, string $column, string $text, string $boolean = 'and'): void
    {
        self::where($query, $column, '%'.self::escape($text).'%', $boolean);
    }
}
