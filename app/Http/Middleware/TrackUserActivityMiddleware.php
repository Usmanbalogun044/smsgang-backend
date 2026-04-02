<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $throttleKey = 'presence:last_seen_update:' . $user->id;
            if (! Cache::has($throttleKey)) {
                Cache::put($throttleKey, true, now()->addSeconds(45));
                $user->forceFill([
                    'is_online' => true,
                    'last_seen_at' => now(),
                ])->save();
            }
        }

        return $next($request);
    }
}
