<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Local accounts only — production installs create their administrator
     * through the installer.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        User::factory()->admin()->create([
            'name' => 'Site Admin',
            'email' => 'admin@documind.test',
            'credits' => 100,
        ]);

        User::factory()->create([
            'name' => 'Demo Member',
            'email' => 'user@documind.test',
        ]);
    }
}
