<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class AdminCountryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min((int) $request->integer('per_page', 100), 500));

        $countries = Country::query()
            ->withCount('servicePrices')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->string('search'));

                $query->where(function ($inner) use ($search) {
                    $inner
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('provider_code', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('is_active'), function ($query) use ($request) {
                $activeRaw = strtolower((string) $request->input('is_active'));
                $isActive = in_array($activeRaw, ['1', 'true', 'yes'], true);

                $query->where('is_active', $isActive);
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->appends($request->query());

        return CountryResource::collection($countries);
    }

    public function store(StoreCountryRequest $request): JsonResponse
    {
        $country = Country::create($request->validated());

        Log::channel('activity')->info('Admin created country', [
            'country_id' => $country->id,
            'name' => $country->name,
        ]);

        return response()->json([
            'country' => new CountryResource($country),
        ], 201);
    }

    public function update(UpdateCountryRequest $request, Country $country): CountryResource
    {
        $country->update($request->validated());

        Log::channel('activity')->info('Admin updated country', [
            'country_id' => $country->id,
            'changes' => $request->validated(),
        ]);

        return new CountryResource($country);
    }

    public function toggle(Country $country): CountryResource
    {
        $country->update([
            'is_active' => !$country->is_active,
        ]);

        Log::channel('activity')->info('Admin toggled country status', [
            'country_id' => $country->id,
            'is_active' => $country->is_active,
        ]);

        return new CountryResource($country);
    }
}
