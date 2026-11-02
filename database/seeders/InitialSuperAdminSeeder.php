<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class InitialSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('emergency.initial_super_admin.email');
        $password = config('emergency.initial_super_admin.password');

        if (blank($email) && blank($password)) {
            $this->command?->warn('No initial super-admin was created. Set INITIAL_SUPER_ADMIN_EMAIL and INITIAL_SUPER_ADMIN_PASSWORD, then run db:seed again.');

            return;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:14', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            throw new InvalidArgumentException('Initial super-admin credentials are invalid: '.implode(' ', $validator->errors()->all()));
        }

        $email = mb_strtolower(trim($email));
        $existingUser = User::withTrashed()->where('email', $email)->first();

        if ($existingUser?->trashed()) {
            throw new RuntimeException('The configured initial super-admin email belongs to a soft-deleted account.');
        }

        $user = $existingUser ?? User::query()->create([
            'name' => 'Super Administrator',
            'email' => $email,
            'password' => $password,
        ]);
        $roleId = Role::query()->where('code', 'SUPER_ADMIN')->value('id');

        if ($roleId === null) {
            throw new RuntimeException('The SUPER_ADMIN role is missing. Run the RBAC seeder first.');
        }

        $user->roles()->syncWithoutDetaching([$roleId]);
        $this->command?->info("Initial super-admin role is configured for {$email}.");
    }
}
