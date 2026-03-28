<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Country;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 80);
        $perPage = max(10, min($perPage, 200));

        $query = Service::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $services = $query
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => ServiceResource::collection($services->getCollection())->resolve(),
            'meta' => [
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
            ],
        ]);
    }

    public function countries(): AnonymousResourceCollection
    {
        $countries = Country::where('is_active', true)->get();

        return CountryResource::collection($countries);
    }

    /**
     * Return synced countries/prices for a service from local DB.
     * This avoids exposing transient 5SIM/network errors to frontend users.
     */
    public function countriesForService(Service $service, PricingService $pricingService): JsonResponse
    {
        $rows = ServicePrice::query()
            ->with('country:id,name,code,flag,is_active')
            ->where('service_id', $service->id)
            ->where('is_active', true)
            ->where('available_count', '>', 0)
            ->whereHas('country', fn ($query) => $query->where('is_active', true))
            ->get();

        $results = $rows
            ->map(function (ServicePrice $row) use ($service, $pricingService): array {
                $country = $row->country;

                $operatorsRaw = is_array($row->provider_payload) ? $row->provider_payload : [];
                $operators = collect($operatorsRaw)
                    ->map(function ($info, $operatorName) use ($pricingService, $row) {
                        if (! is_array($info)) {
                            return null;
                        }

                        $cost = (float) ($info['cost'] ?? 0);
                        $count = (int) ($info['count'] ?? 0);
                        if ($cost <= 0) {
                            return null;
                        }

                        return [
                            'name' => (string) $operatorName,
                            'cost' => $cost,
                            'count' => $count,
                            'final_price' => $pricingService->calculateFinalPrice(
                                $cost,
                                $row->markup_type,
                                (float) $row->markup_value,
                            ),
                        ];
                    })
                    ->filter()
                    ->sortBy('cost')
                    ->values();

                $finalPrice = $pricingService->calculateFinalPrice(
                    (float) $row->provider_price,
                    $row->markup_type,
                    (float) $row->markup_value,
                );

                return [
                    'id' => $country->id,
                    'service' => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'slug' => $service->slug,
                    ],
                    'country' => [
                        'id' => $country->id,
                        'name' => $country->name,
                        'code' => strtoupper($country->code),
                        'flag' => $country->flag,
                    ],
                    'final_price' => $finalPrice,
                    'available_count' => (int) $row->available_count,
                    'operators' => $operators,
                    'is_active' => (bool) $row->is_active,
                ];
            })
            ->sortBy(fn (array $item) => $item['country']['name'], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['data' => $results]);
    }

    public function servicesForCountry(Country $country): AnonymousResourceCollection
    {
        // Return active services (country filtering can be added later)
        return ServiceResource::collection(Service::where('is_active', true)->get());
    }
}

