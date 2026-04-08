<?php

namespace App\Jobs;

use App\Models\SmmService;
use App\Services\SmmPricingService;
use App\Services\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PostTelegramBoostingUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    public function handle(TelegramNotificationService $telegram, SmmPricingService $pricingService): void
    {
        $services = $this->bestBoostingServices($pricingService)
            ->sortByDesc(fn (array $item) => $item['orders_count'])
            ->values()
            ->take(5);

        $excludeServiceIds = $services->pluck('service_id')->all();

        $randomServices = $this->randomBoostingServices($pricingService, $excludeServiceIds)
            ->shuffle()
            ->take(2)
            ->values();

        $offers = $services->concat($randomServices)->take(7)->values();

        if ($offers->isEmpty()) {
            Log::channel('activity')->warning('Telegram boosting update skipped: no active boosting offers found');
            return;
        }

        $message = $this->buildMessage($offers);
        $sent = $telegram->sendChannelPost($message, null);

        if (! $sent) {
            Log::channel('activity')->warning('Telegram boosting update failed to send');
            return;
        }

        Log::channel('activity')->info('Telegram boosting update posted', [
            'offers_count' => $offers->count(),
        ]);
    }

    private function bestBoostingServices(SmmPricingService $pricingService): Collection
    {
        return SmmService::query()
            ->withCount('orders')
            ->where('is_active', true)
            ->orderByDesc('orders_count')
            ->orderBy('name')
            ->get()
            ->map(function (SmmService $service) use ($pricingService) {
                $priceData = $pricingService->calculatePrice($service, 1);

                return [
                    'service_id' => $service->id,
                    'service_name' => trim(html_entity_decode((string) $service->name)),
                    'category' => trim(html_entity_decode((string) ($service->category ?? 'Boosting'))),
                    'final_price' => (float) $priceData['final_price_per_1000'],
                    'orders_count' => (int) $service->orders_count,
                    'min' => (int) $service->min,
                    'max' => (int) $service->max,
                ];
            })
            ->filter(fn (array $item) => $item['final_price'] > 0 && $this->isDisplayableLabel($item['service_name']) && $this->isDisplayableLabel($item['category']))
            ->values();
    }

    private function randomBoostingServices(SmmPricingService $pricingService, array $excludeServiceIds): Collection
    {
        return SmmService::query()
            ->withCount('orders')
            ->where('is_active', true)
            ->when(! empty($excludeServiceIds), fn ($query) => $query->whereNotIn('id', $excludeServiceIds))
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(function (SmmService $service) use ($pricingService) {
                $priceData = $pricingService->calculatePrice($service, 1);

                return [
                    'service_id' => $service->id,
                    'service_name' => trim(html_entity_decode((string) $service->name)),
                    'category' => trim(html_entity_decode((string) ($service->category ?? 'Boosting'))),
                    'final_price' => (float) $priceData['final_price_per_1000'],
                    'orders_count' => (int) $service->orders_count,
                    'min' => (int) $service->min,
                    'max' => (int) $service->max,
                ];
            })
            ->filter(fn (array $item) => $item['final_price'] > 0 && $this->isDisplayableLabel($item['service_name']) && $this->isDisplayableLabel($item['category']))
            ->values();
    }

    private function isDisplayableLabel(string $value): bool
    {
        return (bool) preg_match('/[A-Za-z0-9]/', $value);
    }

    private function buildMessage(Collection $offers): string
    {
        $lines = [];
        $lines[] = '📈 SMSGang Social Media Boosting Update';
        $lines[] = 'Open the app: https://smsgang.org';
        $lines[] = 'Top boosting offers live now:';
        $lines[] = '';

        foreach ($offers as $index => $offer) {
            $rank = $index + 1;
            $lines[] = sprintf(
                "%d) %s - %s",
                $rank,
                $offer['service_name'],
                $offer['category']
            );
            $lines[] = sprintf("   💵 Final price: ₦%s per 1,000 | 📦 Min %s / Max %s", number_format((float) $offer['final_price'], 2), number_format((int) $offer['min']), number_format((int) $offer['max']));
        }

        $lines[] = '';
        $lines[] = '⚡ Open SMSGang and place your boosting order fast.';
        $lines[] = '⏱ Updated every 20 minutes';

        return implode("\n", $lines);
    }
}
