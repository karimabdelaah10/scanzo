<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceType;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Shift;
use App\Services\SubscriptionLimits;
use App\Support\DeviceCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DeviceController extends Controller
{
    public function __construct(protected SubscriptionLimits $limits) {}

    /** GET /api/devices — a manager sees their branch's tills. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = Device::query()
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->unless(
                $request->user()->role->seesAllBranches(),
                fn ($q) => $q->where('branch_id', $request->user()->branch_id),
            )
            ->orderBy('code')
            ->get();

        return DeviceResource::collection($devices);
    }

    /** POST /api/devices */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $this->ownerOnly();

        $request->user()->loadMissing('business');
        $this->limits->assertCanAddDevice($request->user()->business);

        $device = Device::create([
            'branch_id' => $request->input('branch_id'),
            'name' => $request->input('name'),
            'code' => $request->input('code') ?: DeviceCode::next(),
            'type' => DeviceType::tryFrom((string) $request->input('type')) ?? DeviceType::Mobile,
            // A till that has never reached the server cannot sell, so a new
            // one starts inside the grace period rather than locked out.
            'last_sync_at' => now(),
            'is_active' => true,
        ]);

        return (new DeviceResource($device))->response()->setStatusCode(201);
    }

    /** PATCH /api/devices/{device} */
    public function update(UpdateDeviceRequest $request, string $device): DeviceResource
    {
        $this->ownerOnly();

        $model = Device::findOrFail($device);
        $model->update($request->validated());

        return new DeviceResource($model);
    }

    /**
     * DELETE /api/devices/{device}
     *
     * Only a till that never sold. One that did is switched off: its code is
     * in the number of every invoice it wrote, and the code must never be
     * handed to another till.
     */
    public function destroy(string $device): JsonResponse
    {
        $this->ownerOnly();

        $model = Device::findOrFail($device);

        if (Invoice::where('device_id', $model->id)->exists()) {
            throw DomainException::deviceHasHistory($model->code);
        }

        // Shifts cascade with the device, and a shift's count is the only
        // record of that drawer.
        if (Shift::where('device_id', $model->id)->exists()) {
            throw DomainException::shiftsHaveHistory('till', $model->code);
        }

        $model->delete();

        return response()->json(status: 204);
    }

    protected function ownerOnly(): void
    {
        if (Gate::denies('manage-business')) {
            throw DomainException::notAllowedToManageBusiness();
        }
    }
}
