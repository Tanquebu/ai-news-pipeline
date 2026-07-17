<?php

declare(strict_types=1);

namespace App\Support;

class LlmJson
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        return json_decode(self::stripFences($raw), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Il prompt chiede solo JSON, ma i modelli a volte incorniciano comunque
     * la risposta in un fence markdown: qui si tollera senza indebolire il
     * parsing strict (un JSON troncato o malformato continua a lanciare).
     */
    private static function stripFences(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw;
    }
}
