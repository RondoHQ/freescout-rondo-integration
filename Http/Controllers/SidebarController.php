<?php

namespace Modules\RondoIntegration\Http\Controllers;

use App\Conversation;
use App\Thread;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\RondoIntegration\Services\BindingService;
use Modules\RondoIntegration\Services\CustomerEmailService;
use Modules\RondoIntegration\Services\RondoApiClient;
use Modules\RondoIntegration\Services\SettingsService;
use Modules\RondoIntegration\Services\SidebarDocument;
use Modules\RondoIntegration\Services\SportlinkRelationCodeExtractor;

class SidebarController extends Controller
{
    public function load(Request $request, BindingService $bindings, RondoApiClient $rondo, SidebarDocument $document, CustomerEmailService $emails, SettingsService $settings, SportlinkRelationCodeExtractor $relationCodes)
    {
        $request->validate(['conversation_id' => 'required|integer|min:1']);
        $conversation = Conversation::with(['customer.emails', 'mailbox'])->findOrFail((int) $request->conversation_id);
        $agent = auth()->user();
        if (!$agent || !$agent->can('view', $conversation)) {
            abort(403);
        }
        if (!$settings->sidebarEnabledForMailbox($conversation->mailbox_id)) {
            return response()->json(['status' => 'unavailable', 'message' => 'Rondo is not enabled for this mailbox.'], 422);
        }
        $mapping = DB::table('rondo_mailbox_mappings')
            ->where('mailbox_id', $conversation->mailbox_id)
            ->where('state', 'active')
            ->first();
        $binding = $bindings->activeForUser($agent->id);
        if (!$binding) {
            return response()->json(['status' => 'unauthorized', 'message' => 'Sign in with Rondo to view member context.'], 403);
        }
        $firstIncomingThread = $conversation->threads()
            ->where('type', Thread::TYPE_CUSTOMER)
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
        $payload = [
            'version' => 1,
            'mailboxKey' => $mapping ? $mapping->stable_key : 'basis',
            'conversationId' => (int) $conversation->id,
            'conversationNumber' => (int) $conversation->number,
            'customerId' => (int) $conversation->customer_id,
            'customerEmails' => $emails->forConversation($conversation->customer, $firstIncomingThread, $conversation->mailbox),
            'agent' => [
                'freescoutUserId' => (int) $agent->id,
                'issuer' => $binding->issuer,
                'subject' => $binding->subject,
            ],
        ];
        if ($firstIncomingThread) {
            $relationCode = $relationCodes->extract(
                $conversation->subject,
                $firstIncomingThread->from,
                $firstIncomingThread->getBodyOriginal()
            );
            if ($relationCode) {
                $payload['personReference'] = [
                    'type' => 'knvb_id',
                    'value' => $relationCode,
                    'source' => 'sportlink_transfer_request',
                ];
            }
        }
        try {
            $response = $rondo->sidebar($payload);
            if (empty($response['html']) || !is_string($response['html'])) {
                throw new \RuntimeException('sidebar_response_invalid');
            }
            return response()->json(array_merge(['status' => isset($response['status']) ? $response['status'] : 'ok'], $document->render($response['html'])));
        } catch (\Exception $e) {
            return response()->json(['status' => 'unavailable', 'message' => 'Rondo is temporarily unavailable.'], 503);
        }
    }
}
