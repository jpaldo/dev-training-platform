<?php

namespace App\Console\Commands;

use App\Services\DevelopmentAccountProvisioner;
use Database\Seeders\L4FirstSessionSeeder;
use Illuminate\Console\Command;
use LogicException;

class EnsureDevelopmentAccountsCommand extends Command
{
    protected $signature = 'app:ensure-development-accounts';

    protected $description = 'Ensure the demo admin, mentor and participant accounts exist and enroll the demo participants in the first L4 session (local, staging and testing only)';

    public function handle(DevelopmentAccountProvisioner $provisioner): int
    {
        try {
            $count = $provisioner->provision();
            $enrolledCount = $provisioner->enrollInFirstSession();
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Ensured {$count} development accounts.");

        if ($enrolledCount === null) {
            $this->warn(sprintf('Skipped the demo enrollment: the training session "%s" does not exist.', L4FirstSessionSeeder::SESSION_TITLE));
        } else {
            $this->info(sprintf('Ensured %d demo participants in "%s", each with their demo mentor.', $enrolledCount, L4FirstSessionSeeder::SESSION_TITLE));
        }

        return self::SUCCESS;
    }
}
