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
            'vendor_global_markup_virtual' => (float) Setting::get('vendor_global_markup_virtual', 0),
            'vendor_global_markup_smm' => (float) Setting::get('vendor_global_markup_smm', 0),
            'whatsapp_unit_price_ngn' => (float) Setting::get('whatsapp_unit_price_ngn', 20),
            'whatsapp_production_from' => (string) Setting::get('whatsapp_production_from', ''),
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
            'vendor_global_markup_virtual' => 'sometimes|numeric|min:0',
            'vendor_global_markup_smm' => 'sometimes|numeric|min:0',
            'whatsapp_unit_price_ngn' => 'sometimes|numeric|min:0.01',
            'whatsapp_production_from' => 'sometimes|nullable|string|max:40',
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

        if (array_key_exists('vendor_global_markup_virtual', $validated)) {
            Setting::set('vendor_global_markup_virtual', $validated['vendor_global_markup_virtual']);
        }

        if (array_key_exists('vendor_global_markup_smm', $validated)) {
            Setting::set('vendor_global_markup_smm', $validated['vendor_global_markup_smm']);
        }

        if (array_key_exists('whatsapp_unit_price_ngn', $validated)) {
            Setting::set('whatsapp_unit_price_ngn', $validated['whatsapp_unit_price_ngn']);
        }

        if (array_key_exists('whatsapp_production_from', $validated)) {
            Setting::set('whatsapp_production_from', $validated['whatsapp_production_from'] ?? '');
        }

        Setting::set('whatsapp_mode', 'production');

        Log::channel('activity')->info('Admin updated global settings', $validated);

        return response()->json([
            'message'       => 'Settings saved.',
            'global_markup' => (float) Setting::get('global_markup_fixed', 150),
            'global_markup_type' => (string) Setting::get('global_markup_type', 'fixed'),
            'exchange_rate'  => (float) Setting::get('exchange_rate_usd_ngn', 1600),
            'twilio_markup' => (float) Setting::get('twilio_markup_value', 0),
            'twilio_markup_type' => (string) Setting::get('twilio_markup_type', 'fixed'),
            'twilio_default_monthly_price_usd' => (float) Setting::get('twilio_default_monthly_price_usd', 1.2),
            'vendor_global_markup_virtual' => (float) Setting::get('vendor_global_markup_virtual', 0),
            'vendor_global_markup_smm' => (float) Setting::get('vendor_global_markup_smm', 0),
            'whatsapp_unit_price_ngn' => (float) Setting::get('whatsapp_unit_price_ngn', 20),
            'whatsapp_production_from' => (string) Setting::get('whatsapp_production_from', ''),
        ]);
    }
}
