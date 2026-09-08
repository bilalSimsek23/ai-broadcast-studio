<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /studio/live and its session-minting endpoint: the same identity as
 * the admin panel. A guest is sent to the panel login (or gets 401 JSON for an
 * API call); an authenticated non-admin gets 403.
 *
 * The session endpoint mints OpenAI credentials, so it must never be reachable
 * unauthenticated (CLAUDE.md §6 — operator actions are role-gated).
 */
final class EnsureStudioOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort_if($request->expectsJson(), 401, 'Unauthenticated.');

            return redirect()->guest('/admin/login');
        }

        abort_unless($user->is_admin === true, 403);

        return $next($request);
    }
}
