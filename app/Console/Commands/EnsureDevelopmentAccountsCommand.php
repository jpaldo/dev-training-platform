<?php

namespace App\Console\Commands;

use App\Services\DevelopmentAccountProvisioner;
use Illuminate\Console\Command;
use LogicException;

class EnsureDevelopmentAccountsCommand extends Command
{
    protected $signature = 'app:ensure-development-accounts';

    protected $description = 'Ensure the local development admin and coach accounts exist';

    public function handle(DevelopmentAccountProvisioner $provisioner): int
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            $this->error('Development account provisioning is disabled outside local/testing environments.');

            return self::FAILURE;
        }

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
