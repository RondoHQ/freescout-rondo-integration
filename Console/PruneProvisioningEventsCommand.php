<?php

namespace Modules\RondoIntegration\Console;

use Illuminate\Console\Command;
use Modules\RondoIntegration\Services\ProvisioningEventService;

class PruneProvisioningEventsCommand extends Command
{
    protected $signature = 'rondo:prune-provisioning-events {--limit=200}';
    protected $description = 'Prune processed provisioning-event idempotency records after the Rondo retention period';

    public function handle(ProvisioningEventService $events)
    {
        $deleted = $events->prune((int) $this->option('limit'));
        $this->info('Provisioning event records pruned: ' . $deleted . '.');
        return 0;
    }
}
