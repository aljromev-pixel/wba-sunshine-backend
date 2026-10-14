<?php

namespace App\Support;

use App\Models\User;

class InventoryPermissions
{
    public static function canMaintainCatalogue(User $user): bool
    {
        return self::canDeleteInventory($user) || self::hasRole($user, 'Purchasing', 'Manager');
    }

    public static function canDeleteInventory(User $user): bool
    {
        return self::hasRole($user, 'Warehouse', 'Supervisor')
            || self::hasRole($user, 'Warehouse', 'Manager')
            || self::hasRole($user, 'Administration', 'Manager');
    }

    public static function canViewAudit(User $user): bool
    {
        return in_array($user->department.':'.$user->role_level, [
            'Warehouse:Supervisor', 'Warehouse:Manager', 'Sales:Supervisor', 'Purchasing:Manager', 'Administration:Manager',
        ], true);
    }

    public static function canCreateMovement(User $user, string $type): bool
    {
        if (self::hasRole($user, 'Administration', 'Manager')) {
            return true;
        }

        return match ($type) {
            'Stock In', 'Transfer', 'Return' => self::hasWarehouseAccess($user),
            'Stock Out' => self::hasWarehouseAccess($user) || self::hasRole($user, 'Sales', 'Staff') || self::hasRole($user, 'Sales', 'Supervisor'),
            default => false,
        };
    }

    public static function canRequestAdjustment(User $user): bool
    {
        return self::hasWarehouseAccess($user) || self::hasRole($user, 'Administration', 'Manager');
    }

    public static function canReviewAdjustment(User $user): bool
    {
        return self::hasRole($user, 'Warehouse', 'Supervisor')
            || self::hasRole($user, 'Warehouse', 'Manager')
            || self::hasRole($user, 'Purchasing', 'Manager')
            || self::hasRole($user, 'Administration', 'Manager');
    }

    private static function hasWarehouseAccess(User $user): bool
    {
        return $user->department === 'Warehouse' && in_array($user->role_level, ['Staff', 'Supervisor', 'Manager'], true);
    }

    private static function hasRole(User $user, string $department, string $roleLevel): bool
    {
        return $user->department === $department && $user->role_level === $roleLevel;
    }
}
