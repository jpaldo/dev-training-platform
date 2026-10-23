<?php

namespace App\Console\Commands;

use App\Services\DevelopmentAccountProvisioner;
use Illuminate\Console\Command;
use LogicException;

class EnsureDevelopmentAccountsCommand extends Command
{
    protected $signature = 'app:ensure-development-accounts';

    protected $description = 'Ensure the demo admin, mentor and participant accounts exist (local, staging and testing only)';

    public function handle(DevelopmentAccountProvisioner $provisioner): int
    {
        try {
            $count = $provisioner->provision();
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Ensured {$count} development accounts.");

        return self::SUCCESS;
    }
}
