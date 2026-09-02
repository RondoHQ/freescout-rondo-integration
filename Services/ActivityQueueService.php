<?php

namespace Modules\RondoIntegration\Services;

use Illuminate\Support\Facades\DB;

class ActivityQueueService
{
    public function enqueue($eventType, $conversation)
    {
        if (!in_array($eventType, ['conversation_created', 'conversation_customer_changed'], true)
            || !$conversation || !$conversation->id || !$conversation->customer_id || !$conversation->mailbox_id
        ) {
            return;
        }
        try {
            $this->enqueueManaged($eventType, $conversation);
        } catch (\Throwable $failure) {
            try {
                \Log::warning('Rondo activity enqueue failed.', ['exception' => get_class($failure)]);
            } catch (\Throwable $loggingFailure) {
                // Integration queue failures must not block normal FreeScout conversation handling.
            }
        }
    }

    private function enqueueManaged($eventType, $conversation)
    {
        $managed = DB::table('rondo_mailbox_mappings')
            ->where('mailbox_id', $conversation->mailbox_id)
            ->where('state', 'active')
            ->exists();
        if (!$managed) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $key = ['event_type' => $eventType, 'conversation_id' => $conversation->id];
        $existing = DB::table('rondo_activity_delivery_queue')->where($key)->first();
        $values = [
            'customer_id' => $conversation->customer_id,
            'state' => 'pending',
            'next_attempt_at' => $now,
            'last_error_code' => null,
            'updated_at' => $now,
        ];
        if ($existing) {
            DB::table('rondo_activity_delivery_queue')->where('id', $existing->id)->update($values);
            return;
        }
        DB::table('rondo_activity_delivery_queue')->insert(array_merge($key, $values, [
            'attempts' => 0,
            'created_at' => $now,
        ]));
    }
}
