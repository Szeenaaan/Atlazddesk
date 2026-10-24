<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Central role/tenant rules. Legacy service_manager has no implicit admin privileges. */
final class AmcAccess
{
    public const STAFF = ['admin'];
    public const CUSTOMERS = ['customer_admin', 'customer_user'];
    public const ROLES = ['super_admin','admin','technician','customer_admin','customer_user'];

    public static function isSuperAdmin(User $user): bool { return $user->role === 'super_admin'; }
    public static function isAdmin(User $user): bool { return $user->role === 'admin'; }
    public static function isStaff(User $user): bool { return in_array($user->role, self::STAFF, true); }
    public static function isCustomer(User $user): bool { return in_array($user->role, self::CUSTOMERS, true); }
    public static function mayCreateTicket(User $user, ?User $actor = null): bool
    {
        return self::isCustomer($user) && ($actor === null || self::isCustomer($actor));
    }
    public static function mayManage(User $user): bool { return self::isStaff($user); }
    public static function mayAssign(User $user): bool { return self::isStaff($user); }
    public static function mayManageAssets(User $user): bool { return self::isStaff($user) || $user->role === 'customer_admin'; }
    public static function mayManageUsers(User $user): bool { return self::isStaff($user) || $user->role === 'customer_admin'; }

    public static function scopeTicket(Builder $query, User $user): Builder
    {
        if ($user->role === 'customer_admin') {
            abort_unless($user->customer_id, 403);
            return $query->where('customer_id', $user->customer_id);
        }
        if ($user->role === 'customer_user') {
            abort_unless($user->customer_id, 403);
            return $query->where('customer_id', $user->customer_id)->where('created_by', $user->id);
        }
        if ($user->role === 'technician') return $query->where('assigned_to', $user->id);
        abort_unless(self::isStaff($user), 403);
        return $query;
    }

    public static function scopeAssets(Builder $query, User $user): Builder
    {
        $query = self::scopeCustomer($query, $user);
        if ($user->role === 'customer_user') $query->whereIn('assets.id', $user->assignedAssets()->select('assets.id'));
        return $query;
    }

    public static function scopeCustomer(Builder $query, User $user): Builder
    {
        if (self::isCustomer($user)) {
            abort_unless($user->customer_id, 403);
            return $query->where('customer_id', $user->customer_id);
        }
        if ($user->role === 'technician') {
            return $query->whereIn('customer_id', self::assignedCustomerIds($user));
        }
        abort_unless(self::isStaff($user), 403);
        return $query;
    }

    public static function scopeCustomers(Builder $query, User $user): Builder
    {
        if (self::isCustomer($user)) {
            abort_unless($user->customer_id, 403);
            return $query->whereKey($user->customer_id);
        }
        if ($user->role === 'technician') return $query->whereIn('id', self::assignedCustomerIds($user));
        abort_unless(self::isStaff($user), 403);
        return $query;
    }

    private static function assignedCustomerIds(User $user): Builder
    {
        return Ticket::query()->select('customer_id')->where('assigned_to', $user->id);
    }

    public static function customerId(User $user, ?int $requested): int
    {
        if (self::isCustomer($user)) {
            abort_unless($user->customer_id, 403);
            return (int) $user->customer_id;
        }
        abort_unless(self::isStaff($user), 403);
        abort_unless($requested, 422, 'customer_id is required');
        return $requested;
    }
}
