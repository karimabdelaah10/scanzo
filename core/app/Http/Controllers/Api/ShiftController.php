<?php

namespace App\Http\Controllers\Api;

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\OpenShift;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Device;
use App\Models\Shift;
use App\Support\ShopDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    /**
     * GET /api/shifts/current?device_id= — the till's open shift, or null.
     *
     * Open on a seller's own till, so no gate; it never carries the expected
     * cash (see ShiftResource). `stale` is true when the open shift is from an
     * earlier shop day: the till then offers to open a new one, which closes
     * the old one without a count, and never offers to count it.
     */
    public function current(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'uuid'],
            'tz' => ['nullable', 'string', 'max:64'],
        ]);

        // BusinessScope: another tenant's device is a 404.
        $device = Device::findOrFail($request->input('device_id'));

        abort_unless($request->user()->canAccessBranch($device->branch_id), 403);

        $shift = Shift::query()
            ->where('open_device_id', $device->id)
            ->with(['opener', 'device'])
            ->first();

        $tz = ShopDays::timezone($request);

        return response()->json([
            'data' => $shift ? (new ShiftResource($shift))->resolve($request) : null,
            'stale' => $shift !== null
                && $shift->opened_at->copy()->setTimezone($tz)->toDateString() < now($tz)->toDateString(),
        ]);
    }

    /** POST /api/shifts — opens one. 201 whether new or a replayed sync. */
    public function store(StoreShiftRequest $request, OpenShift $openShift): JsonResponse
    {
        $device = Device::findOrFail($request->input('device_id'));

        $result = $openShift->handle(
            user: $request->user(),
            device: $device,
            openingFloat: $request->input('opening_float'),
            openedAt: $request->date('opened_at'),
            shiftId: $request->input('id'),
            timezone: ShopDays::timezone($request),
        );

        $result['shift']->loadMissing(['opener', 'device']);
        $result['auto_closed']?->loadMissing(['opener', 'device']);

        return response()->json([
            'data' => (new ShiftResource($result['shift']))->resolve($request),
            // Yesterday's, closed without a count by this very call: the screen
            // tells whoever opened that it happened.
            'auto_closed' => $result['auto_closed']
                ? (new ShiftResource($result['auto_closed']))->resolve($request)
                : null,
        ], 201);
    }

    /** POST /api/shifts/{shift}/close — the count. The response is the one place the seller learns the expected figure. */
    public function close(CloseShiftRequest $request, string $shift, CloseShift $closeShift): ShiftResource
    {
        $model = $closeShift->handle(
            user: $request->user(),
            shift: Shift::findOrFail($shift),
            countedCash: $request->input('counted_cash'),
            note: $request->input('note'),
            closedAt: $request->date('closed_at'),
        );

        return new ShiftResource($model->load(['opener', 'closer', 'device']));
    }

    /**
     * GET /api/shifts?branch_id=&device_id=&user_id=&status=&from=&to=
     *
     * Managers and the owner: every seller's shifts, with their differences.
     * A manager is pinned to their own branch. `from`/`to` are shop days and
     * filter on when the shift opened.
     */
    public function index(Request $request)
    {
        if (Gate::denies('view-sales')) {
            throw DomainException::notAllowedToViewSales();
        }

        $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // Only shifts that did not balance: a non-zero difference, or a
            // close nobody counted.
            'problems' => ['nullable', 'boolean'],
        ]);

        $query = Shift::query()
            ->with(['opener', 'closer', 'device'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->input('device_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->input('user_id')))
            ->when($request->input('status') === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->when($request->input('status') === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
            ->when($request->boolean('problems'), fn ($q) => $q->whereNotNull('closed_at')->where(
                fn ($w) => $w->where('difference', '!=', 0)->orWhereNull('difference'),
            ))
            ->unless(
                $request->user()->role->seesAllBranches(),
                fn ($q) => $q->where('branch_id', $request->user()->branch_id),
            );

        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->input('from', $request->input('to'));
            $to = $request->input('to', $from);
            $request->merge(['from' => $from, 'to' => $to]);

            [$start, $end] = ShopDays::between($request, 'from', 'to');
            $query->whereBetween('opened_at', [$start, $end]);
        }

        return ShiftResource::collection($query->latest('opened_at')->paginate(25));
    }
}
