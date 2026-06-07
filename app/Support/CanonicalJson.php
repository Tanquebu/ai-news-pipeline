<?php

declare(strict_types=1);

namespace App\Support;

class CanonicalJson
{
    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Preserve sequential arrays (lists) without reordering elements
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }

        ksort($value);

        return array_map(self::sort(...), $value);
    }
}
