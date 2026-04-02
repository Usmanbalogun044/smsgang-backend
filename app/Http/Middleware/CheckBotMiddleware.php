<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        return $next($request);
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
