<?php

namespace App\Models\Scopes;

use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query to the current tenant.
 *
 * When no tenant is set the scope does nothing, which is what the platform
 * admin panel and the migrations need. That is also the dangerous case, so
 * tenant-facing routes must always run behind the SetBusinessContext
 * middleware — the scope is a safety net, not the authorisation itself.
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(BusinessContext::class);

        if (! $context->hasBusiness()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('business_id'),
            $context->businessId()
        );
    }
}
