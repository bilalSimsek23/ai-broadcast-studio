<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the endpoints the PUBLIC broadcast screen (/studio/live) must reach
 * even when it runs remotely / in a separate browser (vMix web input, OBS,
 * another machine): the session mint, the control + state relay, and the
 * one-shot image fetch.
 *
 * Passes when EITHER:
 *  - the request is an authenticated admin (same identity as the panel), OR
 *  - it carries a token (X-Studio-Token header or ?token=) that matches
 *    config('ai.realtime.public_access_token') — and that config value is set.
 *
 * With no configured token the token path is disabled and only an admin
 * passes, so the feature is off by default.
 */
final class StudioBroadcastAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_admin === true) {
            return $next($request);
        }

        $configured = config('ai.realtime.public_access_token');

        if (is_string($configured) && $configured !== '') {
            $presented = $request->header('X-Studio-Token') ?? $request->query('token');

            if (is_string($presented) && hash_equals($configured, $presented)) {
                return $next($request);
            }
        }

        // An authenticated (non-admin) user is forbidden; a guest is
        // unauthenticated — same shape as EnsureStudioOperator.
        abort($user instanceof User ? 403 : 401);
    }
}
