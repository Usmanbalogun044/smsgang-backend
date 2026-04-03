<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'global_markup' => (float) Setting::get('global_markup_fixed', 150),
            'global_markup_type' => Setting::get('global_markup_type', 'fixed'),
            'exchange_rate'  => (float) Setting::get('exchange_rate_usd_ngn', 1600),
            'twilio_markup' => (float) Setting::get('twilio_markup_value', 0),
            'twilio_markup_type' => Setting::get('twilio_markup_type', 'fixed'),
            'twilio_default_monthly_price_usd' => (float) Setting::get('twilio_default_monthly_price_usd', 1.2),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'global_markup' => 'sometimes|numeric|min:0',
            'global_markup_type' => 'sometimes|string|in:fixed,percentage',
            'exchange_rate'  => 'sometimes|numeric|min:0.01',
            'twilio_markup' => 'sometimes|numeric|min:0',
            'twilio_markup_type' => 'sometimes|string|in:fixed,percentage',
            'twilio_default_monthly_price_usd' => 'sometimes|numeric|min:0.01',
        ]);

        if (array_key_exists('global_markup', $validated)) {
            Setting::set('global_markup_fixed', $validated['global_markup']);
        }

        if (array_key_exists('global_markup_type', $validated)) {
            Setting::set('global_markup_type', $validated['global_markup_type']);
        }

        if (array_key_exists('exchange_rate', $validated)) {
            Setting::set('exchange_rate_usd_ngn', $validated['exchange_rate']);
        }

        if (array_key_exists('twilio_markup', $validated)) {
            Setting::set('twilio_markup_value', $validated['twilio_markup']);
        }

        if (array_key_exists('twilio_markup_type', $validated)) {
            Setting::set('twilio_markup_type', $validated['twilio_markup_type']);
        }

        if (array_key_exists('twilio_default_monthly_price_usd', $validated)) {
            Setting::set('twilio_default_monthly_price_usd', $validated['twilio_default_monthly_price_usd']);
        }

        Log::channel('activity')->info('Admin updated global settings', $validated);

        return response()->json([
            'message'       => 'Settings saved.',
            'global_markup' => (float) Setting::get('global_markup_fixed', 150),
            'global_markup_type' => (string) Setting::get('global_markup_type', 'fixed'),
            'exchange_rate'  => (float) Setting::get('exchange_rate_usd_ngn', 1600),
            'twilio_markup' => (float) Setting::get('twilio_markup_value', 0),
            'twilio_markup_type' => (string) Setting::get('twilio_markup_type', 'fixed'),
            'twilio_default_monthly_price_usd' => (float) Setting::get('twilio_default_monthly_price_usd', 1.2),
        ]);
    }
}
