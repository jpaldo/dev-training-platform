<?php

namespace App\Console\Commands;

use App\Services\ExportLifecycleService;
use Illuminate\Console\Command;

class ReconcileGoogleDriveExportsCommand extends Command
{
    protected $signature = 'google-drive:reconcile-exports {--user= : Limit reconciliation to one participant ID}';

    protected $description = 'Mark stale Google Drive exports as failed when no queue work remains';

    public function handle(ExportLifecycleService $lifecycle): int
    {
        $count = $lifecycle->reconcileStale($this->option('user') ? (int) $this->option('user') : null);
        $this->info("Reconciled {$count} stale export(s).");

        return self::SUCCESS;
    }
}
