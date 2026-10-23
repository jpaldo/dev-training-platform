<?php

namespace Database\Seeders;

use App\Services\DevelopmentAccountProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(L4TrainingProgramSeeder::class);
        $this->call(L4FirstSessionSeeder::class);

        app(DevelopmentAccountProvisioner::class)->provision();
    }
}
