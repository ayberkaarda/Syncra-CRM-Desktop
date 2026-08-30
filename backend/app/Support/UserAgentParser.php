<?php

namespace App\Support;

/**
 * Small, dependency-free User-Agent classifier for the session_logs feature
 * (Faz 5 / B). Deliberately NOT using a package (jenssegers/agent etc.) -
 * the project only needs three coarse buckets (device/browser/platform) for
 * an audit trail, not a full UA database.
 *
 * ---------------------------------------------------------------------------
 * ORDERING IS THE WHOLE GAME
 * ---------------------------------------------------------------------------
 * Modern UA strings lie by omission-inclusion:
 *   - Edge:    "... Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0"
 *   - Chrome:  "... AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36"
 *   - Safari:  "... Version/17.1 Safari/605.1.15" (no "Chrome" token)
 *   - Opera:   "... Chrome/120 Safari/537.36 OPR/106"
 * Every Chromium-based browser's UA contains "Safari", and every one of
 * Edge/Opera's UA also contains "Chrome". So the ONLY correct order is
 * most-specific-first: Edge and Opera before Chrome, Chrome before Safari.
 * Firefox carries none of those tokens and is safe to check anywhere before
 * the generic Safari fallback.
 */
class UserAgentParser
{
    private const BOT_PATTERN = '/bot|crawler|spider|curl|wget|python-requests|postman/i';

    /**
     * @return array{device: string, browser: string, platform: string}
     */
    public function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return $this->unknown();
        }

        if (preg_match(self::BOT_PATTERN, $ua)) {
            return [
                'device' => 'bot',
                'browser' => 'unknown',
                'platform' => 'unknown',
            ];
        }

        return [
            'device' => $this->parseDevice($ua),
            'browser' => $this->parseBrowser($ua),
            'platform' => $this->parsePlatform($ua),
        ];
    }

    /**
     * @return array{device: string, browser: string, platform: string}
     */
    private function unknown(): array
    {
        return [
            'device' => 'unknown',
            'browser' => 'unknown',
            'platform' => 'unknown',
        ];
    }

    private function parseDevice(string $ua): string
    {
        if (preg_match('/ipad|tablet(?!.*mobile)|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/mobi|iphone|ipod|android|blackberry|windows phone/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/windows|macintosh|mac os x|linux|x11/i', $ua)) {
            return 'desktop';
        }

        return 'unknown';
    }

    private function parseBrowser(string $ua): string
    {
        // Edge (Chromium-based "Edg/" and legacy "Edge/") - MUST be checked
        // before Chrome, its UA contains both "Chrome" and "Safari".
        if (preg_match('/Edg(?:A|iOS)?\/([\d.]+)/i', $ua, $m) || preg_match('/Edge\/([\d.]+)/i', $ua, $m)) {
            return $this->format('Edge', $m[1]);
        }

        // Opera ("OPR/" modern, "Opera/" legacy) - also contains "Chrome"/"Safari".
        if (preg_match('/OPR\/([\d.]+)/i', $ua, $m) || preg_match('/Opera\/([\d.]+)/i', $ua, $m)) {
            return $this->format('Opera', $m[1]);
        }

        // Firefox - has none of the Chrome/Safari tokens, safe anywhere.
        if (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
            return $this->format('Firefox', $m[1]);
        }

        // Chrome (and Chromium-based browsers not already matched above) -
        // MUST be checked before Safari, its UA also contains "Safari".
        if (preg_match('/(?:Chrome|CriOS)\/([\d.]+)/i', $ua, $m)) {
            return $this->format('Chrome', $m[1]);
        }

        // Safari - real Safari has no "Chrome" token, only "Version/x Safari/y".
        if (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) {
            return $this->format('Safari', $m[1]);
        }

        if (preg_match('/Safari\//i', $ua)) {
            return 'Safari';
        }

        return 'unknown';
    }

    private function parsePlatform(string $ua): string
    {
        if (preg_match('/Windows NT 10\.0/i', $ua)) {
            // Windows 10 and 11 both report "Windows NT 10.0" - there is no
            // reliable UA signal that tells them apart, so this bucket
            // deliberately covers both rather than guessing.
            return 'Windows 10/11';
        }

        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            return 'Windows '.$this->windowsVersionName($m[1]);
        }

        if (preg_match('/iPhone OS ([\d_]+)|CPU OS ([\d_]+)/i', $ua, $m)) {
            $version = str_replace('_', '.', $m[1] ?? $m[2] ?? '');

            return $version !== '' ? 'iOS '.explode('.', $version)[0] : 'iOS';
        }

        if (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            return 'Android '.$m[1];
        }

        if (preg_match('/Mac OS X ([\d_.]+)/i', $ua)) {
            return 'macOS';
        }

        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }

        return 'unknown';
    }

    private function windowsVersionName(string $ntVersion): string
    {
        return match ($ntVersion) {
            '6.3' => '8.1',
            '6.2' => '8',
            '6.1' => '7',
            default => $ntVersion,
        };
    }

    private function format(string $name, string $version): string
    {
        $major = explode('.', $version)[0] ?? $version;

        return $major !== '' ? "{$name} {$major}" : $name;
    }
}
