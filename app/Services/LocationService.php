<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationService
{
    // Resolve IP location info for the current request via IPinfo Lite.
    // Returns null silently on any failure.
    public function getLocationFromRequest(Request $request): ?array
    {
        try {
            $clientIp = $this->extractIp($request);
            $hasUsableClientIp = $clientIp && !$this->isLocalIp($clientIp);

            $tokens = config('services.ipinfo.tokens', []);
            $token  = !empty($tokens) ? $tokens[array_rand($tokens)] : null;

            // Fallback to /lite/me when request does not contain a usable public IP.
            $url = $hasUsableClientIp
                ? 'https://api.ipinfo.io/lite/' . $clientIp . ($token ? '?token=' . $token : '')
                : 'https://api.ipinfo.io/lite/me' . ($token ? '?token=' . $token : '');

            $response = Http::acceptJson()
                ->timeout(5)
                ->get($url);

            if ($response->failed()) {
                Log::warning('LocationService: ipinfo lite request failed', ['ip' => $clientIp]);
                return null;
            }

            $body = $response->json();

            return [
                'country' => $body['country'] ?? null,
                'region' => null,
                'city' => null,
                'loc' => null,
                'country_code' => $body['country_code'] ?? null,
                'continent' => $body['continent'] ?? null,
                'continent_code' => $body['continent_code'] ?? null,
                'asn' => $body['asn'] ?? null,
                'as_name' => $body['as_name'] ?? null,
                'as_domain' => $body['as_domain'] ?? null,
                'ip' => $body['ip'] ?? $clientIp,
                'client_ip' => $hasUsableClientIp ? $clientIp : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('LocationService: IP lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get location data for a specific IP address
     */
    public function getLocationByIp(string $ip): ?array
    {
        try {
            $tokens = config('services.ipinfo.tokens', []);
            $token  = !empty($tokens) ? $tokens[array_rand($tokens)] : null;

            $url = 'https://api.ipinfo.io/lite/' . $ip . ($token ? '?token=' . $token : '');

            $response = Http::acceptJson()
                ->timeout(5)
                ->get($url);

            if ($response->failed()) {
                Log::warning('LocationService: ipinfo lite request failed', ['ip' => $ip]);
                return null;
            }

            $body = $response->json();

            return [
                'country' => $body['country'] ?? null,
                'region' => null,
                'city' => null,
                'loc' => null,
                'country_code' => $body['country_code'] ?? null,
                'continent' => $body['continent'] ?? null,
                'continent_code' => $body['continent_code'] ?? null,
                'asn' => $body['asn'] ?? null,
                'as_name' => $body['as_name'] ?? null,
                'as_domain' => $body['as_domain'] ?? null,
                'ip' => $body['ip'] ?? $ip,
                'client_ip' => $ip,
            ];
        } catch (\Throwable $e) {
            Log::warning('LocationService: IP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function extractIp(Request $request): ?string
    {
        $candidates = [];

        // Common reverse-proxy/CDN headers.
        $headers = [
            'CF-Connecting-IP',
            'True-Client-IP',
            'X-Forwarded-For',
            'X-Real-Ip',
        ];

        foreach ($headers as $header) {
            $value = $request->header($header);
            if (!$value) {
                continue;
            }

            if ($header === 'X-Forwarded-For') {
                foreach (explode(',', $value) as $ip) {
                    $candidates[] = trim($ip);
                }
                continue;
            }

            $candidates[] = trim($value);
        }

        $fallbackIp = $request->ip();
        if ($fallbackIp) {
            $candidates[] = $fallbackIp;
        }

        // Prefer valid public IPs first.
        foreach ($candidates as $candidate) {
            if (
                filter_var(
                    $candidate,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                return $candidate;
            }
        }

        // If only private/reserved IPs exist, return first valid IP and let isLocalIp reject it.
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isLocalIp(string $ip): bool
    {
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        // True when IP is private/reserved/invalid.
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
