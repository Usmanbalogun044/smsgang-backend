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
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'global_markup' => 'required|numeric|min:0',
            'global_markup_type' => 'required|string|in:fixed,percentage',
            'exchange_rate'  => 'required|numeric|min:0.01',
        ]);

        Setting::set('global_markup_fixed', $validated['global_markup']);
        Setting::set('global_markup_type', $validated['global_markup_type']);
        Setting::set('exchange_rate_usd_ngn', $validated['exchange_rate']);

        Log::channel('activity')->info('Admin updated global settings', $validated);

        return response()->json([
            'message'       => 'Settings saved.',
            'global_markup' => (float) $validated['global_markup'],
            'global_markup_type' => $validated['global_markup_type'],
            'exchange_rate'  => (float) $validated['exchange_rate'],
        ]);
    }
}
