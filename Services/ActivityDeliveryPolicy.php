<?php

namespace Modules\RondoIntegration\Services;

class ActivityDeliveryPolicy
{
    const COMPLETE_STATUSES = ['created', 'confirmed', 'moved', 'restored'];
    const RETRY_STATUSES = ['no_match', 'ambiguous'];

    public function outcome(array $response, $conversationId)
    {
        if (!isset($response['conversation_id'])
            || (int) $response['conversation_id'] !== (int) $conversationId
            || !isset($response['status'])
            || !is_string($response['status'])
        ) {
            throw new \RuntimeException('activity_response_invalid');
        }
        if (in_array($response['status'], self::COMPLETE_STATUSES, true)) {
            return 'complete';
        }
        if (in_array($response['status'], self::RETRY_STATUSES, true)) {
            return 'retry';
        }
        throw new \RuntimeException('activity_response_invalid');
    }

    public function retryDelaySeconds($attempt)
    {
        $delays = [60, 300, 900, 3600];
        $index = max(0, min(count($delays) - 1, (int) $attempt - 1));
        return $delays[$index];
    }

    public function errorCode(\Throwable $failure)
    {
        $message = strtolower(trim((string) $failure->getMessage()));
        return preg_match('/^[a-z0-9_]{1,64}$/', $message) ? $message : 'delivery_failed';
    }
}
