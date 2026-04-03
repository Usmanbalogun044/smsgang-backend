<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TwilioMessage;
use App\Models\TwilioNumberSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTwilioSubscriptionController extends Controller
{
    public function stats(): JsonResponse
    {
        $total = TwilioNumberSubscription::query()->count();
        $active = TwilioNumberSubscription::query()->where('status', 'active')->count();
        $renewalDue = TwilioNumberSubscription::query()->where('status', 'renewal_due')->count();
        $grace = TwilioNumberSubscription::query()->where('status', 'grace')->count();
        $expired = TwilioNumberSubscription::query()->where('status', 'expired')->count();
        $autoRenewEnabled = TwilioNumberSubscription::query()->where('auto_renew', true)->count();

        $monthlyRecurringRevenue = (float) TwilioNumberSubscription::query()
            ->whereIn('status', ['active', 'renewal_due', 'grace'])
            ->sum('monthly_price_ngn');

        $messagesToday = TwilioMessage::query()
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'total_subscriptions' => $total,
            'active_subscriptions' => $active,
            'renewal_due_subscriptions' => $renewalDue,
            'grace_subscriptions' => $grace,
            'expired_subscriptions' => $expired,
            'auto_renew_enabled' => $autoRenewEnabled,
            'monthly_recurring_revenue_ngn' => round($monthlyRecurringRevenue, 2),
            'messages_today' => $messagesToday,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,active,renewal_due,grace,expired,cancelled,released'],
            'country' => ['nullable', 'string', 'size:2'],
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TwilioNumberSubscription::query()
            ->with(['user:id,name,email'])
            ->withCount('messages')
            ->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['country'])) {
            $query->where('country_code', strtoupper((string) $validated['country']));
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('phone_number_e164', 'like', '%' . $search . '%')
                    ->orWhere('twilio_number_sid', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 30);

        return response()->json($query->paginate($perPage));
    }

    public function show(TwilioNumberSubscription $subscription): JsonResponse
    {
        $subscription->load([
            'user:id,name,email',
            'messages' => function ($query) {
                $query->latest()->limit(30);
            },
        ]);

        return response()->json([
            'subscription' => $subscription,
        ]);
    }

    public function messages(Request $request, TwilioNumberSubscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $subscription->messages()->latest();

        if (! empty($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }

        $perPage = (int) ($validated['per_page'] ?? 50);

        return response()->json($query->paginate($perPage));
    }
}