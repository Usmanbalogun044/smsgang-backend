<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckBotMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        if ($ip === '') {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($this->isManuallyBlockedIp($ip)) {
            Log::channel('activity')->warning('Blocked manually configured IP', ['ip' => $ip]);
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (Cache::has($this->banKey($ip))) {
            Log::channel('activity')->warning('Blocked banned IP', ['ip' => $ip]);
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $userAgent = (string) $request->header('User-Agent', '');
        if ($userAgent === '' || $this->isBotUserAgent($userAgent)) {
            $this->registerSuspiciousAttempt($ip, 'user_agent', ['user_agent' => $userAgent]);
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $accept = strtolower((string) $request->header('Accept', ''));
        if (! str_contains($accept, 'application/json') && ! str_contains($accept, '*/*')) {
            $this->registerSuspiciousAttempt($ip, 'accept_header', ['accept' => $accept]);
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($request->filled('website')) {
            Cache::put($this->banKey($ip), true, now()->addHours(24));
            Log::channel('activity')->warning('Honeypot trap triggered; IP banned', [
                'ip' => $ip,
            ]);

            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($this->shouldVerifyTurnstile($request)) {
            $turnstileResult = $this->verifyTurnstileToken($request, $ip);
            if ($turnstileResult instanceof Response) {
                return $turnstileResult;
            }
        }

        return $next($request);
    }

    private function shouldVerifyTurnstile(Request $request): bool
    {
        if (! $request->isMethod('post')) {
            return false;
        }

        // Enforce Turnstile on signup endpoint. Keep other auth endpoints unchanged for now.
        return $request->is('api/register');
    }

    private function verifyTurnstileToken(Request $request, string $ip): Response|bool
    {
        $secret = (string) config('services.cloudflare.turnstile_secret', '');
        if ($secret === '') {
            return true;
        }

        $token = (string) $request->input('cf_turnstile_token', '');
        if ($token === '') {
            $this->registerSuspiciousAttempt($ip, 'turnstile_missing');
            return response()->json(['message' => 'Complete human verification and try again.'], 422);
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            if (! $response->successful()) {
                $this->registerSuspiciousAttempt($ip, 'turnstile_http_failed', ['status' => $response->status()]);
                return response()->json(['message' => 'Human verification failed. Please try again.'], 422);
            }

            $payload = $response->json();
            $success = (bool) ($payload['success'] ?? false);

            $expectedHostname = strtolower(trim((string) config('services.cloudflare.turnstile_hostname', '')));
            $actualHostname = strtolower(trim((string) ($payload['hostname'] ?? '')));

            if ($expectedHostname !== '' && $actualHostname !== '' && $expectedHostname !== $actualHostname) {
                $this->registerSuspiciousAttempt($ip, 'turnstile_hostname_mismatch', [
                    'expected' => $expectedHostname,
                    'actual' => $actualHostname,
                ]);

                return response()->json(['message' => 'Human verification failed.'], 422);
            }

            if (! $success) {
                $this->registerSuspiciousAttempt($ip, 'turnstile_invalid', [
                    'error_codes' => $payload['error-codes'] ?? [],
                ]);

                return response()->json(['message' => 'Human verification failed.'], 422);
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('activity')->warning('Turnstile verification exception', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Human verification unavailable. Please try again.'], 422);
        }
    }

    private function isManuallyBlockedIp(string $ip): bool
    {
        $blocked = array_filter(array_map('trim', explode(',', (string) env('BLOCKED_IPS', ''))));
        return in_array($ip, $blocked, true);
    }

    private function banKey(string $ip): string
    {
        return 'security:banned_ip:' . $ip;
    }

    private function attemptsKey(string $ip): string
    {
        return 'security:suspicious_attempts:' . $ip;
    }

    private function registerSuspiciousAttempt(string $ip, string $reason, array $context = []): void
    {
        $attempts = (int) Cache::increment($this->attemptsKey($ip));
        Cache::put($this->attemptsKey($ip), $attempts, now()->addHours(2));

        if ($attempts >= 6) {
            Cache::put($this->banKey($ip), true, now()->addHours(24));
            Log::channel('activity')->warning('IP auto-banned for repeated suspicious requests', [
                'ip' => $ip,
                'attempts' => $attempts,
                'reason' => $reason,
                'context' => $context,
            ]);
            return;
        }

        Log::channel('activity')->warning('Suspicious request blocked', [
            'ip' => $ip,
            'attempts' => $attempts,
            'reason' => $reason,
            'context' => $context,
        ]);
    }

    private function isBotUserAgent(string $userAgent): bool
    {
        $bots = [
            'bot', 'crawl', 'slurp', 'spider', 'curl', 'wget', 'python', 'java',
            'headless', 'postman', 'insomnia', 'guzzle', 'httpclient', 'requests',
            'libwww', 'mechanize', 'puppeteer', 'selenium', 'phantomjs', 'urllib',
        ];

        if (app()->environment('local')) {
            $bots = array_values(array_diff($bots, ['postman', 'insomnia', 'curl']));
        }

        foreach ($bots as $bot) {
            if (str_contains(strtolower($userAgent), $bot)) {
                return true;
            }
        }

        return false;
    }
}
