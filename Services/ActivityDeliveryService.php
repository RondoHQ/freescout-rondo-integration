<?php

namespace Modules\RondoIntegration\Services;

use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\DB;

class ActivityDeliveryService
{
    const BATCH_SIZE = 25;
    const CLAIM_TTL = 600;

    private $rondo;
    private $emails;
    private $policy;

    public function __construct(RondoApiClient $rondo, CustomerEmailService $emails, ActivityDeliveryPolicy $policy)
    {
        $this->rondo = $rondo;
        $this->emails = $emails;
        $this->policy = $policy;
    }

    public function run($limit = self::BATCH_SIZE)
    {
        $this->releaseStaleClaims();
        $limit = max(1, min(self::BATCH_SIZE, (int) $limit));
        $now = gmdate('Y-m-d H:i:s');
        $rows = DB::table('rondo_activity_delivery_queue')
            ->whereIn('state', ['pending', 'retry'])
            ->where(function ($query) use ($now) {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $totals = ['processed' => 0, 'delivered' => 0, 'retrying' => 0, 'ignored' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if (!$this->claim($row)) {
                continue;
            }
            $totals['processed']++;
            try {
                $response = $this->deliver($row);
                if ($response === null) {
                    $this->remove($row->id);
                    $totals['ignored']++;
                    continue;
                }
                if ($this->policy->outcome($response, $row->conversation_id) === 'complete') {
                    $this->remove($row->id);
                    $totals['delivered']++;
                    continue;
                }
                $this->retry($row, $response['status']);
                $totals['retrying']++;
            } catch (\Throwable $failure) {
                $this->retry($row, $this->policy->errorCode($failure));
                $totals['failed']++;
            }
        }

        return $totals;
    }

    private function deliver($row)
    {
        $conversation = Conversation::with(['customer.emails'])->find((int) $row->conversation_id);
        if (!$conversation || !$conversation->customer) {
            throw new \RuntimeException('conversation_unavailable');
        }
        $mapping = DB::table('rondo_mailbox_mappings')
            ->where('mailbox_id', $conversation->mailbox_id)
            ->where('state', 'active')
            ->first();
        if (!$mapping) {
            return null;
        }

        $createdAt = strtotime((string) $conversation->created_at);
        $actor = null;
        $threadId = (int) ($row->thread_id ?? 0);
        if (in_array((string) $row->event_type, ['customer_replied', 'user_replied'], true)) {
            $thread = Thread::find($threadId);
            if (!$thread || (int) $thread->conversation_id !== (int) $conversation->id || (int) $thread->state !== Thread::STATE_PUBLISHED) {
                throw new \RuntimeException('thread_unavailable');
            }
            $expectedType = (string) $row->event_type === 'customer_replied' ? Thread::TYPE_CUSTOMER : Thread::TYPE_MESSAGE;
            if ((int) $thread->type !== $expectedType) {
                throw new \RuntimeException('thread_type_invalid');
            }
            $createdAt = strtotime((string) $thread->created_at);
            if ((string) $row->event_type === 'user_replied' && (int) $thread->created_by_user_id > 0) {
                $userId = (int) $thread->created_by_user_id;
                $binding = DB::table('rondo_oidc_bindings')
                    ->where(function ($query) use ($userId) {
                        $query->where('active_user_id', $userId)->orWhere('last_user_id', $userId);
                    })
                    ->orderByDesc('id')
                    ->first();
                if ($binding) {
                    $actor = [
                        'freescoutUserId' => (int) $thread->created_by_user_id,
                        'issuer' => (string) $binding->issuer,
                        'subject' => (string) $binding->subject,
                    ];
                }
            }
        }
        if ($createdAt === false) {
            throw new \RuntimeException($threadId > 0 ? 'thread_timestamp_invalid' : 'conversation_timestamp_invalid');
        }
        $payload = [
            'version' => 1,
            'eventType' => (string) $row->event_type,
            'instance' => rtrim((string) config('app.url'), '/'),
            'mailboxKey' => (string) $mapping->stable_key,
            'conversationId' => (int) $conversation->id,
            'customerId' => (int) $conversation->customer_id,
            'customerEmails' => $this->emails->fromCustomer($conversation->customer),
            'subject' => $this->plainSubject($conversation->subject),
            'createdAt' => gmdate(DATE_ATOM, $createdAt),
        ];
        if ($threadId > 0) {
            $payload['eventId'] = $threadId;
        }
        if ($actor !== null) {
            $payload['actor'] = $actor;
        }
        return $this->rondo->activity($payload);
    }

    private function plainSubject($subject)
    {
        $subject = strip_tags((string) $subject);
        $subject = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject);
        $subject = trim(is_string($subject) ? $subject : '');
        return mb_substr($subject, 0, 998);
    }

    private function claim($row)
    {
        return DB::table('rondo_activity_delivery_queue')
            ->where('id', $row->id)
            ->where('state', $row->state)
            ->update(['state' => 'processing', 'updated_at' => gmdate('Y-m-d H:i:s')]) === 1;
    }

    private function retry($row, $reason)
    {
        $attempts = (int) $row->attempts + 1;
        DB::table('rondo_activity_delivery_queue')->where('id', $row->id)->where('state', 'processing')->update([
            'attempts' => $attempts,
            'state' => 'retry',
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $this->policy->retryDelaySeconds($attempts)),
            'last_error_code' => (string) $reason,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function remove($id)
    {
        DB::table('rondo_activity_delivery_queue')->where('id', $id)->where('state', 'processing')->delete();
    }

    private function releaseStaleClaims()
    {
        DB::table('rondo_activity_delivery_queue')
            ->where('state', 'processing')
            ->where('updated_at', '<=', gmdate('Y-m-d H:i:s', time() - self::CLAIM_TTL))
            ->update([
                'state' => 'retry',
                'next_attempt_at' => gmdate('Y-m-d H:i:s'),
                'last_error_code' => 'delivery_interrupted',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }
}
