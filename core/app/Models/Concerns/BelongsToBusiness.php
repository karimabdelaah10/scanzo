<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Every tenant-owned model uses this: it scopes all reads to the current
 * business and stamps business_id on create, so no query or insert can forget
 * the tenant by accident.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            if ($model->business_id !== null) {
                return;
            }

            $businessId = app(BusinessContext::class)->businessId();

            if ($businessId === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s without a business. Set BusinessContext or pass business_id explicitly.',
                    static::class
                ));
            }

            $model->business_id = $businessId;
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
