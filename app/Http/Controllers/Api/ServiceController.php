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
        $popularSlugs = config('popular-services.priority_order', []);

        $query = Service::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (!empty($popularSlugs)) {
            $caseParts = [];
            $bindings = [];

            foreach ($popularSlugs as $index => $slug) {
                $caseParts[] = 'WHEN ? THEN '.$index;
                $bindings[] = $slug;
            }

            $query->orderByRaw(
                'CASE slug '.implode(' ', $caseParts).' ELSE 9999 END',
                $bindings
            );
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
        $authUser = request()->user();

        $rows = ServicePrice::query()
            ->with('country:id,name,code,flag,is_active')
            ->where('service_id', $service->id)
            ->where('is_active', true)
            ->where('available_count', '>', 0)
            ->whereHas('country', fn ($query) => $query->where('is_active', true))
            ->get();

        $results = $rows
            ->map(function (ServicePrice $row) use ($service, $pricingService, $authUser): array {
                $country = $row->country;

                $operatorsRaw = is_array($row->provider_payload) ? $row->provider_payload : [];
                $operators = collect($operatorsRaw)
                    ->map(function ($info, $operatorName) use ($pricingService, $row, $service, $country, $authUser) {
                        if (! is_array($info)) {
                            return null;
                        }

                        $cost = (float) ($info['cost'] ?? 0);
                        $count = (int) ($info['count'] ?? 0);
                        if ($cost <= 0) {
                            return null;
                        }

                        $operator = [
                            'name' => (string) $operatorName,
                            'count' => $count,
                            'final_price' => $pricingService->calculateFinalPrice(
                                $cost,
                                $row->markup_type,
                                (float) $row->markup_value,
                                $authUser,
                                $service,
                                $country,
                            ),
                        ];

                        // Extract success rates from 5SIM API response
                        if (isset($info['rate'])) {
                            $operator['success_rates'] = [
                                'instant' => (float) ($info['rate'] ?? 0),
                                '1h' => (float) ($info['rate1'] ?? 0),
                                '3h' => (float) ($info['rate3'] ?? 0),
                                '24h' => (float) ($info['rate24'] ?? 0),
                                '72h' => (float) ($info['rate72'] ?? 0),
                                '168h' => (float) ($info['rate168'] ?? 0),
                                '30d' => (float) ($info['rate720'] ?? 0),
                            ];
                        }

                        return $operator;
                    })
                    ->filter()
                    ->sortBy('final_price')
                    ->values();

                $finalPrice = $pricingService->calculateFinalPrice(
                    (float) $row->provider_price,
                    $row->markup_type,
                    (float) $row->markup_value,
                    $authUser,
                    $service,
                    $country,
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

    public function operatorsForServiceCountry(Service $service, Country $country, PricingService $pricingService): JsonResponse
    {
        $authUser = request()->user();

        $row = ServicePrice::query()
            ->with('country:id,name,code,flag,is_active')
            ->where('service_id', $service->id)
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->where('available_count', '>', 0)
            ->whereHas('country', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();

        $operatorsRaw = is_array($row->provider_payload) ? $row->provider_payload : [];
        $operators = collect($operatorsRaw)
            ->map(function ($info, $operatorName) use ($pricingService, $row, $service, $country, $authUser) {
                if (! is_array($info)) {
                    return null;
                }

                $cost = (float) ($info['cost'] ?? 0);
                $count = (int) ($info['count'] ?? 0);
                if ($cost <= 0) {
                    return null;
                }

                $operator = [
                    'name' => (string) $operatorName,
                    'count' => $count,
                    'final_price' => $pricingService->calculateFinalPrice(
                        $cost,
                        $row->markup_type,
                        (float) $row->markup_value,
                        $authUser,
                        $service,
                        $country,
                    ),
                ];

                if (isset($info['rate'])) {
                    $operator['success_rates'] = [
                        'instant' => (float) ($info['rate'] ?? 0),
                        '1h' => (float) ($info['rate1'] ?? 0),
                        '3h' => (float) ($info['rate3'] ?? 0),
                        '24h' => (float) ($info['rate24'] ?? 0),
                        '72h' => (float) ($info['rate72'] ?? 0),
                        '168h' => (float) ($info['rate168'] ?? 0),
                        '30d' => (float) ($info['rate720'] ?? 0),
                    ];
                }

                return $operator;
            })
            ->filter()
            ->sortBy(fn (array $operator) => [
                -1 * ((float) ($operator['success_rates']['1h'] ?? $operator['success_rates']['instant'] ?? 0)),
                (float) $operator['final_price'],
                -1 * ((int) $operator['count']),
            ])
            ->values();

        $bestOperator = $operators->first();

        return response()->json([
            'data' => [
                'id' => $row->id,
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
                'final_price' => $pricingService->calculateFinalPrice(
                    (float) $row->provider_price,
                    $row->markup_type,
                    (float) $row->markup_value,
                    $authUser,
                    $service,
                    $country,
                ),
                'available_count' => (int) $row->available_count,
                'operator_count' => (int) $operators->count(),
                'best_operator' => $bestOperator,
                'operators' => $operators,
                'is_active' => (bool) $row->is_active,
            ],
        ]);
    }

    public function servicesForCountry(Country $country): AnonymousResourceCollection
    {
        // Return active services (country filtering can be added later)
        return ServiceResource::collection(Service::where('is_active', true)->get());
    }
}

