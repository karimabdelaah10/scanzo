<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended business can look at its data and do nothing else.
 *
 * Anything that is not GET or HEAD is refused with `business_suspended`, so
 * catalogue, stock, team and settings are covered along with selling. Signing
 * out is the one write let through: someone has to be able to leave.
 *
 * This is the first layer. CreateInvoice and CreateSaleReturn keep their own
 * check, so a caller that never goes through HTTP (an import, the offline
 * sync later) is still stopped.
 *
 * Only the business's `status` matters here. An ended subscription does not
 * make a shop read only — it is shown, never enforced.
 */
class EnsureBusinessIsWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $this->isReadOnlyRequest($request)) {
            $user->loadMissing('business');

            if ($user->business->status->isReadOnly()) {
                throw DomainException::businessSuspended();
            }
        }

        return $next($request);
    }

    private function isReadOnlyRequest(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->is('api/logout');
    }
}
