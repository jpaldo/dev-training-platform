<?php

namespace App\Console\Commands;

use App\Services\DevelopmentAccountProvisioner;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Console\Command;
use LogicException;

class EnsureDevelopmentAccountsCommand extends Command
{
    protected $signature = 'app:ensure-development-accounts';

    protected $description = 'Ensure the demo admin, mentor and participant accounts exist and enroll the demo participants in the L4 curriculum (local, staging and testing only)';

    public function handle(DevelopmentAccountProvisioner $provisioner): int
    {
        try {
            $count = $provisioner->provision();
            $enrolledCount = $provisioner->enrollInL4Program();
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Ensured {$count} development accounts.");

        if ($enrolledCount === null) {
            $this->warn(sprintf('Skipped the demo enrollment: the curriculum "%s" does not exist.', L4TrainingProgramSeeder::PROGRAM_NAME));
        } else {
            $this->info(sprintf('Ensured %d demo participants in "%s", each with their demo mentor.', $enrolledCount, L4TrainingProgramSeeder::PROGRAM_NAME));
        }

        return self::SUCCESS;
    }
}
