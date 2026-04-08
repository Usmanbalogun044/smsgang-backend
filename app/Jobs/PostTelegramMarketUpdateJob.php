<?php

namespace App\Jobs;

use App\Models\ServicePrice;
use App\Services\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PostTelegramMarketUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    public function handle(TelegramNotificationService $telegram): void
    {
        $popularSlugs = array_values(array_filter((array) config('popular-services.priority_order', [])));

        $popularOffers = $this->bestOffersForSlugs($popularSlugs)
            ->shuffle()
            ->values();

        $popularPickCount = min(3, $popularOffers->count());
        $pickedPopular = $popularOffers->take($popularPickCount)->values();

        $excludeServiceIds = $pickedPopular->pluck('service_id')->all();

        $randomOffers = $this->bestOffersExcludingServiceIds($excludeServiceIds)
            ->shuffle()
            ->take(max(0, 7 - $popularPickCount))
            ->values();

        $offers = $pickedPopular->concat($randomOffers)->shuffle()->take(7)->values();

        if ($offers->isEmpty()) {
            Log::channel('activity')->warning('Telegram market update skipped: no active offers found');
            return;
        }

        $message = $this->buildMessage($offers);
        $sent = $telegram->sendChannelPost($message, null);

        if (! $sent) {
            Log::channel('activity')->warning('Telegram market update failed to send');
            return;
        }

        Log::channel('activity')->info('Telegram market update posted', [
            'offers_count' => $offers->count(),
        ]);
    }

    /**
     * Pick best (lowest final_price) offer per popular service slug.
     */
    private function bestOffersForSlugs(array $slugs): Collection
    {
        if (empty($slugs)) {
            return collect();
        }

        return ServicePrice::query()
            ->with(['service:id,name,slug', 'country:id,name,code'])
            ->where('is_active', true)
            ->where('available_count', '>', 0)
            ->whereHas('service', fn ($query) => $query->where('is_active', true)->whereIn('slug', $slugs))
            ->whereHas('country', fn ($query) => $query->where('is_active', true))
            ->get()
            ->groupBy('service_id')
            ->map(function (Collection $rows) {
                $best = $rows->sortBy('final_price')->first();
                return $best ? $this->mapOffer($best) : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Pick best offers for non-popular services (for variety).
     */
    private function bestOffersExcludingServiceIds(array $excludeServiceIds): Collection
    {
        return ServicePrice::query()
            ->with(['service:id,name,slug', 'country:id,name,code'])
            ->where('is_active', true)
            ->where('available_count', '>', 0)
            ->whereHas('service', function ($query) use ($excludeServiceIds) {
                $query->where('is_active', true);

                if (! empty($excludeServiceIds)) {
                    $query->whereNotIn('id', $excludeServiceIds);
                }
            })
            ->whereHas('country', fn ($query) => $query->where('is_active', true))
            ->limit(500)
            ->get()
            ->groupBy('service_id')
            ->map(function (Collection $rows) {
                $best = $rows->sortBy('final_price')->first();
                return $best ? $this->mapOffer($best) : null;
            })
            ->filter()
            ->values();
    }

    private function mapOffer(ServicePrice $price): array
    {
        return [
            'service_id' => $price->service_id,
            'service_name' => (string) ($price->service->name ?? 'Unknown'),
            'service_slug' => (string) ($price->service->slug ?? ''),
            'country_name' => (string) ($price->country->name ?? 'Unknown'),
            'country_code' => strtoupper((string) ($price->country->code ?? '')),
            'final_price' => (float) $price->final_price,
            'available_count' => (int) ($price->available_count ?? 0),
        ];
    }

    private function buildMessage(Collection $offers): string
    {
        $lines = [];
        $lines[] = '🔥 SMSGang Virtual Number Update';
        $lines[] = 'Open the app: https://smsgang.org';
        $lines[] = 'Top virtual number offers live now:';
        $lines[] = '';

        foreach ($offers as $index => $offer) {
            $rank = $index + 1;
            $lines[] = sprintf(
                "%d) %s - %s %s",
                $rank,
                $offer['service_name'],
                $offer['country_name'],
                $offer['country_code'] !== '' ? '(' . $offer['country_code'] . ')' : ''
            );
            $lines[] = sprintf("   💵 Final price: ₦%s | 📦 %s available", number_format((float) $offer['final_price'], 2), number_format((int) $offer['available_count']));
        }

        $lines[] = '';
        $lines[] = '⚡ Open SMSGang now and grab your number fast.';
        $lines[] = '⏱ Updated every 20 minutes';

        return implode("\n", $lines);
    }
}
