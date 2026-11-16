<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamUserRequest;
use App\Http\Requests\UpdateTeamUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Invoice;
use App\Models\Shift;
use App\Models\User;
use App\Services\SubscriptionLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TeamController extends Controller
{
    public function __construct(protected SubscriptionLimits $limits) {}

    /** GET /api/users */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ownerOnly();

        $users = User::query()
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    /** POST /api/users */
    public function store(StoreTeamUserRequest $request): JsonResponse
    {
        $this->ownerOnly();

        $request->user()->loadMissing('business');
        $this->limits->assertCanAddUser($request->user()->business);

        $data = $request->validated();

        // An owner is not tied to a branch, whatever was sent.
        if (Role::from($data['role']) === Role::Owner) {
            $data['branch_id'] = null;
        }

        $user = User::create($data + ['is_active' => true]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /** PATCH /api/users/{user} */
    public function update(UpdateTeamUserRequest $request, string $user): UserResource
    {
        $this->ownerOnly();

        $model = User::findOrFail($user);
        $data = $request->validated();

        // Locking yourself out, or demoting yourself out of the only owner
        // account, leaves a business nobody can administer.
        if ($model->id === $request->user()->id) {
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw DomainException::cannotChangeOwnAccount('You cannot switch off your own account.');
            }

            if (array_key_exists('role', $data) && Role::from($data['role']) !== Role::Owner) {
                throw DomainException::cannotChangeOwnAccount('You cannot take the owner role off your own account.');
            }
        }

        // Only an *active* owner counts towards the "last owner" rule. A
        // suspended owner is already outside activeOwnerCount(), so demoting
        // or switching them off cannot make things worse.
        //
        // EnsureAccountIsActive keeps a suspended owner out, so the caller is
        // always an active owner and the cases above already stop them
        // removing themselves. This is the backstop for two owners switching
        // each other off at the same moment.
        $losesOwnership = $model->isOwner() && $model->is_active && (
            (array_key_exists('role', $data) && Role::from($data['role']) !== Role::Owner)
            || (array_key_exists('is_active', $data) && ! $data['is_active'])
        );

        if ($losesOwnership && $this->activeOwnerCount() <= 1) {
            throw DomainException::cannotRemoveLastOwner();
        }

        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        if (array_key_exists('role', $data) && Role::from($data['role']) === Role::Owner) {
            $data['branch_id'] = null;
        }

        $model->update($data);

        return new UserResource($model);
    }

    /**
     * DELETE /api/users/{user}
     *
     * Only an account that never sold anything. One that did is switched off,
     * because deleting it would blank out who served the customer on every
     * invoice they ever wrote.
     */
    public function destroy(Request $request, string $user): JsonResponse
    {
        $this->ownerOnly();

        $model = User::findOrFail($user);

        if ($model->id === $request->user()->id) {
            throw DomainException::cannotChangeOwnAccount('You cannot delete your own account.');
        }

        // Same rule as in update(): a suspended owner is not counted, so
        // removing one never leaves the business without an active owner.
        if ($model->isOwner() && $model->is_active && $this->activeOwnerCount() <= 1) {
            throw DomainException::cannotRemoveLastOwner();
        }

        if (Invoice::where('user_id', $model->id)->exists() || Shift::where('user_id', $model->id)->exists()) {
            throw DomainException::userHasHistory($model->name);
        }

        $model->delete();

        return response()->json(status: 204);
    }

    protected function activeOwnerCount(): int
    {
        return User::where('role', Role::Owner)->where('is_active', true)->count();
    }

    protected function ownerOnly(): void
    {
        if (Gate::denies('manage-business')) {
            throw DomainException::notAllowedToManageBusiness();
        }
    }
}
