<?php

namespace Modules\RondoIntegration\Console;

use Illuminate\Console\Command;
use Modules\RondoIntegration\Services\ActivityDeliveryService;

class DeliverActivitiesCommand extends Command
{
    protected $signature = 'rondo:deliver-activities {--limit=25}';
    protected $description = 'Deliver queued FreeScout conversation activity pointers to Rondo';

    public function handle(ActivityDeliveryService $delivery)
    {
        $result = $delivery->run((int) $this->option('limit'));
        $this->info(
            'Activity delivery complete: processed=' . $result['processed']
            . ', delivered=' . $result['delivered']
            . ', retrying=' . $result['retrying']
            . ', ignored=' . $result['ignored']
            . ', failed=' . $result['failed'] . '.'
        );
        return $result['failed'] ? 1 : 0;
    }
}
