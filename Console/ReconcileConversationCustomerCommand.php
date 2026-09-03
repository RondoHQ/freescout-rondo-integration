<?php

namespace Modules\RondoIntegration\Console;

use App\Conversation;
use App\Thread;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\RondoIntegration\Services\ActivityQueueService;
use Modules\RondoIntegration\Services\ConversationCustomerService;
use Modules\RondoIntegration\Services\SettingsService;

class ReconcileConversationCustomerCommand extends Command
{
    protected $signature = 'rondo:reconcile-conversation-customer {conversation} {--apply}';
    protected $description = 'Preview or repair the customer of one mailbox-domain conversation';

    public function handle(ConversationCustomerService $customers, ActivityQueueService $activities, SettingsService $settings)
    {
        $conversation = Conversation::with('mailbox')->find((int) $this->argument('conversation'));
        if (!$conversation) {
            $this->error('Conversation not found.');
            return 1;
        }
        if (!$settings->sidebarEnabledForMailbox($conversation->mailbox_id)) {
            $this->error('Rondo sidebar is not enabled for this mailbox.');
            return 1;
        }
        $thread = $conversation->threads()
            ->where('type', Thread::TYPE_CUSTOMER)
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
        $result = $customers->reconcile($conversation, $thread, $conversation->mailbox);
        $this->line('Result: ' . $result['status'] . '.');
        if (!in_array($result['status'], ['changed', 'unchanged'], true)) {
            return 1;
        }
        $this->line('Target customer ID: ' . $result['target_customer_id'] . '.');
        if ($result['status'] !== 'changed' || !$this->option('apply')) {
            if ($result['status'] === 'changed') {
                $this->info('Preview only; rerun with --apply to save this change.');
            }
            return 0;
        }

        DB::transaction(function () use ($conversation) {
            $conversation->save();
        });
        $activities->enqueue('conversation_customer_changed', $conversation);
        $this->info('Conversation customer reconciled.');
        return 0;
    }
}
