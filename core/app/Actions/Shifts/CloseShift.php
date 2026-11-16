<?php

namespace App\Actions\Shifts;

use App\Enums\ShiftCloseKind;
use App\Exceptions\DomainException;
use App\Models\Shift;
use App\Models\User;
use App\Services\DrawerCash;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closes a shift against the count of the drawer.
 *
 * The expected figure is worked out HERE, from the counted amount's own
 * window, and stored together with the signed difference. It is never shown
 * before the count: the caller gets it back only in the response to the count,
 * and an open shift's resource does not carry it. Whoever sees the expected
 * number first writes it down as the count, the difference is always zero, and
 * the feature stops meaning anything.
 *
 * Who may close: a seller, their own shift. A branch manager, any shift in
 * their branch. The owner, any.
 */
class CloseShift
{
    public function __construct(protected DrawerCash $drawer) {}

    public function handle(
        User $user,
        Shift $shift,
        string|int|float $countedCash,
        ?string $note = null,
        ?\DateTimeInterface $closedAt = null,
    ): Shift {
        $user->loadMissing('business');

        if (! $user->business->status->canSell()) {
            throw DomainException::businessSuspended();
        }

        if (! $user->canAccessBranch($shift->branch_id)) {
            throw DomainException::branchNotAllowed();
        }

        if ($user->isSeller() && $shift->user_id !== $user->id) {
            throw DomainException::shiftNotYours();
        }

        $closedAt = Carbon::instance($closedAt ?? now());

        return DB::transaction(function () use ($user, $shift, $countedCash, $note, $closedAt) {
            // Two people closing the same shift, or a retried sync: the second
            // sees the first's result and is told, not allowed to overwrite it.
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw DomainException::shiftAlreadyClosed();
            }

            if ($closedAt->lt($locked->opened_at)) {
                throw DomainException::shiftClosedBeforeItOpened();
            }

            $counted = max(0, Money::toMinor($countedCash));

            $expected = $this->drawer->expectedMinor(
                $locked->device_id,
                Money::toMinor($locked->opening_float),
                $locked->opened_at,
                $closedAt,
            );

            $locked->forceFill([
                'closed_at' => $closedAt,
                'closed_by' => $user->id,
                'expected_cash' => Money::toDecimal($expected),
                'counted_cash' => Money::toDecimal($counted),
                // Signed: negative is a shortage. Not hidden, not corrected.
                'difference' => Money::toDecimal($counted - $expected),
                'close_kind' => ShiftCloseKind::Counted,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'open_device_id' => null,
                'synced_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
