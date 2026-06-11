<?php

namespace App\Support\Alpha;

final class Role
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const TEACHER = 'teacher';

    public const PARENT = 'parent';

    public const PRINCIPAL = 'principal';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::ADMIN,
            self::TEACHER,
            self::PARENT,
            self::PRINCIPAL,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            self::TEACHER => 'Guru',
            self::PARENT => 'Orang Tua',
            self::PRINCIPAL => 'Kepala Sekolah',
        ];
    }

    public static function label(?string $role): string
    {
        return self::labels()[$role] ?? 'Pengguna';
    }

    public static function hasGlobalAccess(?string $role): bool
    {
        return in_array($role, [self::SUPER_ADMIN, self::ADMIN, self::PRINCIPAL], true);
    }
}
