<?php

declare(strict_types=1);

namespace App\Support\Http;

final class CanonicalOrigin
{
    public static function from(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $parts = parse_url($raw);

        return is_array($parts) ? self::fromParts($parts) : null;
    }

    /**
     * @param  array<string, mixed>  $parts  pre-parsed url parts (output of parse_url)
     */
    public static function fromParts(array $parts): ?string
    {
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
