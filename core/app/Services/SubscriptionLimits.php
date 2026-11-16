<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\User;

/**
 * What a plan allows.
 *
 * Enforced at the moment something is created rather than by a nightly job,
 * because a shop that has already trained four sellers will not accept being
 * told next week that it was only allowed three.
 *
 * A null limit means unlimited.
 */
class SubscriptionLimits
{
    public function assertCanAddBranch(Business $business): void
    {
        $limit = $this->limit($business, 'max_branches');

        if ($limit !== null && Branch::count() >= $limit) {
            throw DomainException::planLimitReached('branches', $limit);
        }
    }

    public function assertCanAddUser(Business $business): void
    {
        $limit = $this->limit($business, 'max_users');

        // Switched-off accounts do not count: a shop that replaced a seller
        // should not have to delete the old record to hire the new one.
        if ($limit !== null && User::where('is_active', true)->count() >= $limit) {
            throw DomainException::planLimitReached('users', $limit);
        }
    }

    public function assertCanAddDevice(Business $business): void
    {
        $limit = $this->limit($business, 'max_devices');

        if ($limit !== null && Device::where('is_active', true)->count() >= $limit) {
            throw DomainException::planLimitReached('devices', $limit);
        }
    }

    /** @return array<string, array{used: int, limit: int|null}> */
    public function usage(Business $business): array
    {
        return [
            'branches' => [
                'used' => Branch::count(),
                'limit' => $this->limit($business, 'max_branches'),
            ],
            'users' => [
                'used' => User::where('is_active', true)->count(),
                'limit' => $this->limit($business, 'max_users'),
            ],
            'devices' => [
                'used' => Device::where('is_active', true)->count(),
                'limit' => $this->limit($business, 'max_devices'),
            ],
        ];
    }

    protected function limit(Business $business, string $field): ?int
    {
        $business->loadMissing('subscription');

        $value = $business->subscription?->{$field};

        return $value === null ? null : (int) $value;
    }
}
