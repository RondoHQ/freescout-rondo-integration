<?php

namespace Modules\RondoIntegration\Services;

use Illuminate\Support\Facades\DB;

class ActivityQueueService
{
    public function enqueue($eventType, $conversation)
    {
        if (!$conversation || !$conversation->id || !$conversation->customer_id) {
            return;
        }
        DB::table('rondo_activity_delivery_queue')->updateOrInsert(
            ['event_type' => $eventType, 'conversation_id' => $conversation->id],
            [
                'customer_id' => $conversation->customer_id,
                'state' => 'pending',
                'next_attempt_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }
}

