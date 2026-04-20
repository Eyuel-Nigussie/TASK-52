<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a per-user inactivity timeout on Sanctum tokens.
 *
 * Sanctum auto-updates the underlying token's last_used_at on every
 * authenticated request, so it is not a reliable source of idle time.
 * We track idle time ourselves in the cache, keyed by token id.
 */
class InactivityTimeoutMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if (!$token || !isset($token->id)) {
            return $next($request);
        }

        $timeoutMinutes = $user->inactivity_timeout
            ?? (int) config('vetops.inactivity_timeout', 15);

        $cacheKey = "vetops.token_idle:{$token->id}";
        $lastSeenIso = Cache::get($cacheKey);

        if ($lastSeenIso !== null) {
            $lastSeen = Carbon::parse($lastSeenIso);
            if ($lastSeen->diffInMinutes(now()) >= $timeoutMinutes) {
                $token->delete();
                Cache::forget($cacheKey);
                return response()->json([
                    'message' => 'Session expired due to inactivity.',
                    'code'    => 'SESSION_EXPIRED',
                ], 401);
            }
        }

        // Refresh last-seen checkpoint — give it a generous TTL so it
        // doesn't evict before the next request.
        Cache::put(
            $cacheKey,
            now()->toIso8601String(),
            now()->addMinutes($timeoutMinutes + 60),
        );

        return $next($request);
    }
}
