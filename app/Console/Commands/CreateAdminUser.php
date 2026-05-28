<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Create a Platform Admin (super_admin) user — the only non-manual user-creation path';

    public function handle(): int
    {
        $name = text(
            label: 'Name',
            required: true,
        );

        $email = text(
            label: 'Email',
            required: true,
            validate: fn (string $value): ?string => match (true) {
                ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
                User::where('email', $value)->exists() => 'A user with this email already exists.',
                default => null,
            },
        );

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value): ?string => strlen($value) >= 8
                ? null
                : 'Password must be at least 8 characters.',
        );

        $admin = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'tenant_id' => null,
        ]);

        UserRole::create([
            'user_id' => $admin->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        $this->info("Platform Admin created: {$admin->email}");

        return self::SUCCESS;
    }
}
