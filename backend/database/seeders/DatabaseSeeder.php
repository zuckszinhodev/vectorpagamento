<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AcquirerSeeder::class,
            CategorySeeder::class,
            WhiterAccountSeeder::class,
        ]);

        $this->command->info('✅ Database seeded successfully!');
    }
}
