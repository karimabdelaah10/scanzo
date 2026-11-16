<?php

namespace App\Providers;

use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request, job or command. BusinessScope reads it.
        $this->app->singleton(BusinessContext::class);
    }

    public function boot(): void
    {
        // Fail loudly on a missing relationship or a mass-assigned unknown
        // attribute while developing, instead of silently writing nothing.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerGates();
    }

    /**
     * Who may do what inside a business. Branch scoping is separate and lives
     * on the user (canAccessBranch), because these answer "what", not "where".
     */
    protected function registerGates(): void
    {
        // Pricing, products and what is on sale.
        Gate::define('manage-catalogue', fn (User $user) => ! $user->isSeller());

        // What was sold: the day's totals, by payment method and by seller.
        Gate::define('view-sales', fn (User $user) => ! $user->isSeller());

        // Receiving goods and correcting counts. Selling moves stock too, but
        // through the till, not through these endpoints.
        Gate::define('manage-stock', fn (User $user) => ! $user->isSeller());

        // Cancelling an invoice: a branch manager (own branch) or the owner.
        Gate::define('void-invoices', fn (User $user) => ! $user->isSeller());

        // The margin. A seller sees the price, never the cost.
        Gate::define('view-cost', fn (User $user) => $user->role->seesCost());

        // Branches, users, devices, settings.
        Gate::define('manage-business', fn (User $user) => $user->isOwner());
    }
}
