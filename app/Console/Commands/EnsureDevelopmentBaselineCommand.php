<?php

namespace App\Console\Commands;

use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Console\Command;

class EnsureDevelopmentBaselineCommand extends Command
{
    protected $signature = 'app:ensure-development-baseline';

    protected $description = 'Ensure the local QA training reference baseline exists';

    public function handle(): int
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            $this->error('Development baseline provisioning is disabled outside local/testing environments.');

            return self::FAILURE;
        }

        app(QaTrainingProgramSeeder::class)->run();
        $this->info('Ensured the QA training baseline.');

        return self::SUCCESS;
    }
}
