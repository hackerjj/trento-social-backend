<?php

namespace App\Support;

/**
 * Parser de user-agent ligero por regex, sin dependencia de pago (NFR-7).
 * Devuelve device/browser/os; null en cada campo que no reconoce.
 */
class UserAgentParser
{
    /**
     * @return array{device: ?string, browser: ?string, os: ?string}
     */
    public static function parse(?string $ua): array
    {
        $result = ['device' => null, 'browser' => null, 'os' => null];

        if ($ua === null || trim($ua) === '') {
            return $result;
        }

        $result['os'] = self::detectOs($ua);
        $result['browser'] = self::detectBrowser($ua);
        $result['device'] = self::detectDevice($ua);

        return $result;
    }

    private static function detectOs(string $ua): ?string
    {
        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/Windows NT/i', $ua)) {
            return 'Windows';
        }
        if (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }

        return null;
    }

    private static function detectBrowser(string $ua): ?string
    {
        // El orden importa: Edge/Opera/Chrome comparten tokens con otros.
        if (preg_match('/Edg(?:e|A|iOS)?\//i', $ua)) {
            return 'Edge';
        }
        if (preg_match('/OPR\/|Opera/i', $ua)) {
            return 'Opera';
        }
        if (preg_match('/SamsungBrowser/i', $ua)) {
            return 'Samsung Internet';
        }
        if (preg_match('/Firefox|FxiOS/i', $ua)) {
            return 'Firefox';
        }
        if (preg_match('/CriOS|Chrome|Chromium/i', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/Safari/i', $ua)) {
            return 'Safari';
        }

        return null;
    }

    private static function detectDevice(string $ua): ?string
    {
        if (preg_match('/iPad|Tablet/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua)) {
            return 'mobile';
        }
        if (preg_match('/Windows NT|Macintosh|Mac OS X|Linux|X11/i', $ua)) {
            return 'desktop';
        }

        return null;
    }
}
