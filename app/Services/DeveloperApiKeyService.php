<?php

namespace App\Services;

use App\Models\DeveloperApiKey;
use App\Models\User;
use Illuminate\Support\Str;

class DeveloperApiKeyService
{
    public function create(User $user, string $name, array $abilities = ['*']): array
    {
        // Enterprise behavior: keep only one active key per user.
        $this->revokeAllActiveForUser($user);

        $plainKey = 'sgk_' . Str::random(48);
        $prefix = substr($plainKey, 0, 18);

        $apiKey = DeveloperApiKey::create([
            'user_id' => $user->id,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $plainKey),
            'abilities' => array_values($abilities),
        ]);

        return [
            'api_key' => $apiKey,
            'plain_key' => $plainKey,
        ];
    }

    public function getActiveForUser(User $user): ?DeveloperApiKey
    {
        return DeveloperApiKey::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->latest()
            ->first();
    }

    public function revokeAllActiveForUser(User $user): void
    {
        DeveloperApiKey::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }

    public function findActiveByPlainKey(string $plainKey): ?DeveloperApiKey
    {
        $hash = hash('sha256', $plainKey);

        return DeveloperApiKey::query()
            ->with('user')
            ->where('key_hash', $hash)
            ->whereNull('revoked_at')
            ->first();
    }

    public function revoke(DeveloperApiKey $apiKey): bool
    {
        return (bool) $apiKey->update([
            'revoked_at' => now(),
        ]);
    }
}