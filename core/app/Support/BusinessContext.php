<?php

namespace App\Support;

use App\Models\Business;

/**
 * The current tenant for this request, job or command.
 *
 * Registered as a singleton. In HTTP requests it is set from the authenticated
 * user by the SetBusinessContext middleware. In queued jobs and artisan
 * commands nothing is authenticated, so whatever dispatches the work must set
 * it explicitly — or call forgetBusiness() and pass business_id by hand.
 */
class BusinessContext
{
    protected ?string $businessId = null;

    protected ?Business $business = null;

    public function setBusinessId(?string $businessId): void
    {
        $this->businessId = $businessId;
        $this->business = null;
    }

    public function setBusiness(?Business $business): void
    {
        $this->business = $business;
        $this->businessId = $business?->getKey();
    }

    public function businessId(): ?string
    {
        return $this->businessId;
    }

    public function business(): ?Business
    {
        if ($this->business === null && $this->businessId !== null) {
            $this->business = Business::withoutGlobalScopes()->find($this->businessId);
        }

        return $this->business;
    }

    public function hasBusiness(): bool
    {
        return $this->businessId !== null;
    }

    public function forgetBusiness(): void
    {
        $this->businessId = null;
        $this->business = null;
    }

    /**
     * Run a callback as another tenant, then restore the previous one.
     * For the platform admin panel and for cross-tenant maintenance jobs.
     */
    public function runFor(string $businessId, callable $callback): mixed
    {
        $previousId = $this->businessId;
        $previous = $this->business;

        $this->setBusinessId($businessId);

        try {
            return $callback();
        } finally {
            $this->businessId = $previousId;
            $this->business = $previous;
        }
    }
}
