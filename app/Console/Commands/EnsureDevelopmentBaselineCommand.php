<?php

namespace App\Console\Commands;

use Database\Seeders\L4FirstSessionSeeder;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Console\Command;

class EnsureDevelopmentBaselineCommand extends Command
{
    protected $signature = 'app:ensure-development-baseline';

    protected $description = 'Ensure the local L4 training reference baseline exists';

    public function handle(): int
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            $this->error('Development baseline provisioning is disabled outside local/testing environments.');

            return self::FAILURE;
        }

        app(L4TrainingProgramSeeder::class)->run();
        app(L4FirstSessionSeeder::class)->run();
        $this->info('Ensured the L4 training baseline.');

        return self::SUCCESS;
    }
}
