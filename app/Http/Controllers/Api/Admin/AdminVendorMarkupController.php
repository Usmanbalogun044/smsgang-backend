<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\SmmService;
use App\Models\User;
use App\Models\VendorSmmServiceMarkup;
use App\Models\VendorVirtualServiceMarkup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVendorMarkupController extends Controller
{
    public function listVirtual(Request $request): JsonResponse
    {
        $query = VendorVirtualServiceMarkup::query()
            ->with(['user:id,name,email,role', 'service:id,name,slug', 'country:id,name,code']);

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', (int) $request->input('service_id'));
        }

        return response()->json([
            'data' => $query->latest()->paginate((int) $request->input('per_page', 50)),
        ]);
    }

    public function storeVirtual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'markup_type' => ['required', 'string', 'in:fixed,percent'],
            'markup_value' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::findOrFail((int) $validated['user_id']);
        if ($user->role !== UserRole::Vendor) {
            return response()->json(['message' => 'Selected user is not a vendor.'], 422);
        }

        Service::findOrFail((int) $validated['service_id']);

        $markup = VendorVirtualServiceMarkup::updateOrCreate(
            [
                'user_id' => (int) $validated['user_id'],
                'service_id' => (int) $validated['service_id'],
                'country_id' => $validated['country_id'] ?? null,
            ],
            [
                'markup_type' => strtolower((string) $validated['markup_type']),
                'markup_value' => (float) $validated['markup_value'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]
        );

        return response()->json([
            'message' => 'Vendor virtual markup saved.',
            'data' => $markup->load(['user:id,name,email,role', 'service:id,name,slug', 'country:id,name,code']),
        ]);
    }

    public function updateVirtual(Request $request, int $markup): JsonResponse
    {
        $record = VendorVirtualServiceMarkup::findOrFail($markup);

        $validated = $request->validate([
            'markup_type' => ['sometimes', 'string', 'in:fixed,percent'],
            'markup_value' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $record->update([
            'markup_type' => isset($validated['markup_type']) ? strtolower((string) $validated['markup_type']) : $record->markup_type,
            'markup_value' => array_key_exists('markup_value', $validated) ? (float) $validated['markup_value'] : $record->markup_value,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $record->is_active,
        ]);

        return response()->json([
            'message' => 'Vendor virtual markup updated.',
            'data' => $record->fresh(['user:id,name,email,role', 'service:id,name,slug', 'country:id,name,code']),
        ]);
    }

    public function destroyVirtual(int $markup): JsonResponse
    {
        $record = VendorVirtualServiceMarkup::findOrFail($markup);
        $record->delete();

        return response()->json([
            'message' => 'Vendor virtual markup deleted.',
        ]);
    }

    public function listSmm(Request $request): JsonResponse
    {
        $query = VendorSmmServiceMarkup::query()
            ->with(['user:id,name,email,role', 'smmService:id,name,category']);

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('smm_service_id')) {
            $query->where('smm_service_id', (int) $request->input('smm_service_id'));
        }

        return response()->json([
            'data' => $query->latest()->paginate((int) $request->input('per_page', 50)),
        ]);
    }

    public function storeSmm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'smm_service_id' => ['required', 'integer', 'exists:smm_services,id'],
            'markup_type' => ['required', 'string', 'in:fixed,percent'],
            'markup_value' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::findOrFail((int) $validated['user_id']);
        if ($user->role !== UserRole::Vendor) {
            return response()->json(['message' => 'Selected user is not a vendor.'], 422);
        }

        SmmService::findOrFail((int) $validated['smm_service_id']);

        $markup = VendorSmmServiceMarkup::updateOrCreate(
            [
                'user_id' => (int) $validated['user_id'],
                'smm_service_id' => (int) $validated['smm_service_id'],
            ],
            [
                'markup_type' => strtolower((string) $validated['markup_type']),
                'markup_value' => (float) $validated['markup_value'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]
        );

        return response()->json([
            'message' => 'Vendor SMM markup saved.',
            'data' => $markup->load(['user:id,name,email,role', 'smmService:id,name,category']),
        ]);
    }

    public function updateSmm(Request $request, int $markup): JsonResponse
    {
        $record = VendorSmmServiceMarkup::findOrFail($markup);

        $validated = $request->validate([
            'markup_type' => ['sometimes', 'string', 'in:fixed,percent'],
            'markup_value' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $record->update([
            'markup_type' => isset($validated['markup_type']) ? strtolower((string) $validated['markup_type']) : $record->markup_type,
            'markup_value' => array_key_exists('markup_value', $validated) ? (float) $validated['markup_value'] : $record->markup_value,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $record->is_active,
        ]);

        return response()->json([
            'message' => 'Vendor SMM markup updated.',
            'data' => $record->fresh(['user:id,name,email,role', 'smmService:id,name,category']),
        ]);
    }

    public function destroySmm(int $markup): JsonResponse
    {
        $record = VendorSmmServiceMarkup::findOrFail($markup);
        $record->delete();

        return response()->json([
            'message' => 'Vendor SMM markup deleted.',
        ]);
    }
}
