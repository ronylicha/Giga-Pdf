<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $this->call(SpatieRolesAndPermissionsSeeder::class);

        // Note: Super admin users should be created manually or through a separate seeder
        // They don't belong to any tenant
    }
}
