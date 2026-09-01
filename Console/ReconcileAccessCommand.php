<?php

namespace Modules\RondoIntegration\Console;

use Illuminate\Console\Command;
use Modules\RondoIntegration\Services\AccessReconciler;

class ReconcileAccessCommand extends Command
{
    protected $signature = 'rondo:reconcile-access {--user=}';
    protected $description = 'Reconcile managed FreeScout mailbox access from current Rondo authorization';

    public function handle(AccessReconciler $reconciler)
    {
        $result = $reconciler->run($this->option('user'));
        $this->info('Reconciliation complete: users=' . $result['users'] . ', failed=' . $result['failed'] . '.');
        return $result['failed'] ? 1 : 0;
    }
}
