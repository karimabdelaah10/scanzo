<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the authenticated user's business into the container, so BusinessScope
 * can constrain every query for the rest of the request.
 *
 * Every tenant-facing route group must run behind this. Without it the scope
 * does nothing and queries would reach across tenants.
 */
class SetBusinessContext
{
    public function __construct(protected BusinessContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only a shop user carries a tenant. An Admin has none and must never
        // set one, whichever guard happens to be the default on this request.
        if ($user instanceof User) {
            $this->context->setBusinessId($user->business_id);
        }

        return $next($request);
    }
}
