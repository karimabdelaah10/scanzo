<?php

namespace App\Actions\Shifts;

use App\Enums\ShiftCloseKind;
use App\Exceptions\DomainException;
use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use App\Services\DrawerCash;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a till's shift with the float that is in the drawer.
 *
 * ONE OPEN SHIFT PER DEVICE, and what happens to yesterday's:
 *   - an open shift from an EARLIER shop day is closed here automatically,
 *     without a count (`close_kind` = auto, no counted amount, no difference),
 *     dated at the till's last sale, and returned so the screen can say so;
 *   - an open shift from the SAME day is refused (`shift_already_open`): two
 *     people are sharing a drawer and somebody has to count it.
 * The till is switched off every night, so an unclosed shift is the normal
 * case rather than an error, and refusing to open until someone fixes
 * yesterday would stop the shop from trading. Counting the drawer at 9am
 * against yesterday's expected would produce a fake difference, so we never
 * offer that: the expected figure is kept and the missing count is visible.
 */
class OpenShift
{
    public function __construct(protected DrawerCash $drawer) {}

    /**
     * @return array{shift: Shift, auto_closed: ?Shift}
     */
    public function handle(
        User $user,
        Device $device,
        string|int|float $openingFloat,
        ?\DateTimeInterface $openedAt = null,
        ?string $shiftId = null,
        ?string $timezone = null,
    ): array {
        $user->loadMissing('business');

        if (! $user->business->status->canSell()) {
            throw DomainException::businessSuspended();
        }

        if (! $user->canAccessBranch($device->branch_id)) {
            throw DomainException::branchNotAllowed();
        }

        // A device retrying a sync must not open a second shift.
        if ($shiftId !== null && ($existing = Shift::find($shiftId)) !== null) {
            return ['shift' => $existing, 'auto_closed' => null];
        }

        if (! $device->is_active) {
            throw DomainException::deviceInactive($device->code);
        }

        $openedAt = Carbon::instance($openedAt ?? now());
        $timezone ??= config('app.timezone');

        try {
            return DB::transaction(function () use ($user, $device, $openingFloat, $openedAt, $shiftId, $timezone) {
                $autoClosed = null;

                $open = Shift::query()->where('open_device_id', $device->id)->lockForUpdate()->first();

                if ($open !== null) {
                    $sameDay = $open->opened_at->copy()->setTimezone($timezone)->toDateString()
                        === $openedAt->copy()->setTimezone($timezone)->toDateString();

                    if ($sameDay) {
                        $open->loadMissing('opener');

                        throw DomainException::shiftAlreadyOpen($device->code, $open->id, $open->opener?->name);
                    }

                    $autoClosed = $this->closeUncounted($open);
                }

                $shift = new Shift([
                    'branch_id' => $device->branch_id,
                    'device_id' => $device->id,
                    'user_id' => $user->id,
                    'opening_float' => Money::toDecimal(max(0, Money::toMinor($openingFloat))),
                    'opened_at' => $openedAt,
                    'open_device_id' => $device->id,
                    'synced_at' => now(),
                ]);

                if ($shiftId !== null) {
                    $shift->id = $shiftId;
                }

                $shift->save();

                return ['shift' => $shift, 'auto_closed' => $autoClosed];
            });
        } catch (UniqueConstraintViolationException) {
            // Two phones opened the same till in the same instant; the unique
            // index let one through. The loser is told, not given a 500.
            $winner = Shift::query()->where('open_device_id', $device->id)->with('opener')->first();

            throw DomainException::shiftAlreadyOpen($device->code, $winner?->id ?? '', $winner?->opener?->name);
        }
    }

    /** Closes a forgotten shift with its expected cash and no count. */
    private function closeUncounted(Shift $shift): Shift
    {
        $closedAt = $this->drawer->lastActivityAt($shift->device_id, $shift->opened_at);

        $expected = $this->drawer->expectedMinor(
            $shift->device_id,
            Money::toMinor($shift->opening_float),
            $shift->opened_at,
            $closedAt,
        );

        $shift->forceFill([
            'closed_at' => $closedAt,
            'closed_by' => null,
            'expected_cash' => Money::toDecimal($expected),
            'counted_cash' => null,
            'difference' => null,
            'close_kind' => ShiftCloseKind::Auto,
            'open_device_id' => null,
        ])->save();

        return $shift;
    }
}
