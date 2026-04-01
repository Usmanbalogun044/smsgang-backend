<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(Service::latest()->paginate(50));
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = Service::create($request->validated());

        Log::channel('activity')->info('Admin created service', [
            'service_id' => $service->id,
            'name' => $service->name,
        ]);

        return response()->json([
            'service' => new ServiceResource($service),
        ], 201);
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service->update($request->validated());

        Log::channel('activity')->info('Admin updated service', [
            'service_id' => $service->id,
            'changes' => $request->validated(),
        ]);

        return new ServiceResource($service);
    }

    public function pendingImages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $validated['limit'] ?? 10;
        $popularSlugs = config('popular-services.priority_order', []);

        $query = Service::query()
            ->where('is_active', true)
            ->whereNull('image_url');

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
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'provider_service_code']);

        return response()->json([
            'count' => $services->count(),
            'services' => $services,
        ]);
    }

    public function uploadImage(Request $request, Service $service): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('image');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filename = Str::slug($service->slug ?: $service->name).'-'.$service->id.'.'.$extension;
        $path = 'service-images/'.$filename;

        Storage::disk('cloudflare-r2')->put($path, file_get_contents($file->getRealPath()), [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType() ?: 'image/png',
        ]);

        $url = Storage::disk('cloudflare-r2')->url($path);
        if (!$url) {
            $base = rtrim((string) config('filesystems.disks.cloudflare-r2.url'), '/');
            $url = $base ? $base.'/'.$path : $path;
        }

        $service->update([
            'image_url' => $url,
        ]);

        Log::channel('activity')->info('Service image uploaded', [
            'service_id' => $service->id,
            'path' => $path,
        ]);

        return response()->json([
            'message' => 'Image uploaded successfully',
            'service_id' => $service->id,
            'image_url' => $service->image_url,
            'path' => $path,
        ]);
    }
}
