<?php

namespace App\Policies;

use App\Enums\ActivationStatus;
use App\Models\Activation;
use App\Models\User;

class ActivationPolicy
{
    public function view(User $user, Activation $activation): bool
    {
        return $user->id === $activation->order->user_id || $user->isAdmin();
    }

    public function cancel(User $user, Activation $activation): bool
    {
        if ($user->id !== $activation->order->user_id && ! $user->isAdmin()) {
            return false;
        }

        return in_array($activation->status, [
            ActivationStatus::Requested,
            ActivationStatus::NumberReceived,
            ActivationStatus::WaitingSms,
        ]);
    }
}
