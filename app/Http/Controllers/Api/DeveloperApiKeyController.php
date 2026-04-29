<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeveloperApiKey;
use App\Services\DeveloperApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeveloperApiKeyController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $keys = DeveloperApiKey::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->latest()
            ->get(['id', 'name', 'key_prefix', 'abilities', 'last_used_at', 'revoked_at', 'expires_at', 'created_at']);

        return response()->json([
            'data' => $keys,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $result = app(DeveloperApiKeyService::class)->create(
            $request->user(),
            $validated['name'],
            ['*'],
        );

        return response()->json([
            'message' => 'API key generated successfully. Previous active key (if any) was revoked.',
            'api_key' => [
                'id' => $result['api_key']->id,
                'name' => $result['api_key']->name,
                'key_prefix' => $result['api_key']->key_prefix,
                'abilities' => $result['api_key']->abilities,
                'created_at' => $result['api_key']->created_at,
            ],
            'plain_key' => $result['plain_key'],
        ], 201);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
        ]);

        $active = $this->developerApiKeyService->getActiveForUser($request->user());
        $name = $validated['name'] ?? $active?->name ?? 'Primary API Key';

        $result = $this->developerApiKeyService->create(
            $request->user(),
            $name,
            ['*'],
        );

        return response()->json([
            'message' => 'API key regenerated successfully. Previous key was revoked.',
            'api_key' => [
                'id' => $result['api_key']->id,
                'name' => $result['api_key']->name,
                'key_prefix' => $result['api_key']->key_prefix,
                'abilities' => $result['api_key']->abilities,
                'created_at' => $result['api_key']->created_at,
            ],
            'plain_key' => $result['plain_key'],
        ], 201);
    }

    public function destroy(Request $request, DeveloperApiKey $developerApiKey): JsonResponse
    {
        if ($developerApiKey->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You cannot revoke this key.',
            ], 403);
        }

        $this->developerApiKeyService->revoke($developerApiKey);

        return response()->json([
            'message' => 'API key revoked.',
        ]);
    }
}