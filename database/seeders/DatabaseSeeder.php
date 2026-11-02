<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            InitialSuperAdminSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoDispatchSeeder::class);
        }
    }
}
