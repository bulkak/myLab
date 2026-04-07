<?php

declare(strict_types=1);

namespace App\Util;

/**
 * URL-safe encoding of arbitrary metric display names (UTF-8), including "/" and other symbols.
 */
final class MetricDynamicsToken
{
    public static function encode(string $name): string
    {
        return rtrim(strtr(base64_encode($name), '+/', '-_'), '=');
    }

    public static function decode(string $token): string
    {
        $b64 = strtr($token, '-_', '+/');
        $pad = \strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException('Invalid metric token');
        }

        return $decoded;
    }
}
