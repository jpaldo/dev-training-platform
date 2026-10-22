<?php

namespace Database\Seeders;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\DevelopmentAccountProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(QaTrainingProgramSeeder::class);

        User::query()->updateOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User', 'password' => Hash::make('password'), 'role' => UserRole::Participant, 'training_path' => TrainingPath::Manual, 'is_active' => true,
        ]);

        app(DevelopmentAccountProvisioner::class)->provision();
    }
}
