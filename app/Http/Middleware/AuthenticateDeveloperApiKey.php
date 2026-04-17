<?php

namespace App\Http\Middleware;

use App\Services\DeveloperApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDeveloperApiKey
{
    public function __construct(
        private DeveloperApiKeyService $developerApiKeyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainKey = trim((string) $request->header('X-API-Key', ''));

        if ($plainKey === '') {
            $authHeader = trim((string) $request->header('Authorization', ''));
            if (str_starts_with(strtolower($authHeader), 'bearer ')) {
                $plainKey = trim(substr($authHeader, 7));
            }
        }

        if ($plainKey === '') {
            return response()->json([
                'message' => 'API key is required.',
            ], 401);
        }

        $apiKey = $this->developerApiKeyService->findActiveByPlainKey($plainKey);

        if (! $apiKey || ! $apiKey->user) {
            return response()->json([
                'message' => 'Invalid API key.',
            ], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->setUserResolver(fn () => $apiKey->user);
        $request->attributes->set('developer_api_key', $apiKey);

        return $next($request);
    }
}