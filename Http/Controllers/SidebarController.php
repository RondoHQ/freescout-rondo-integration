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
        $saving = $request->route()->getName() === 'rondointegration.sidebar.link';
        $rules = ['conversation_id' => 'required|integer|min:1'];
        if ($saving) {
            $rules += ['person_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1', 'context' => 'required|string|size:64'];
        }
        $request->validate($rules);
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
            'instance' => rtrim((string) config('app.url'), '/'),
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
            $sender = $firstIncomingThread->getCreatedBy();
            if ($sender && method_exists($sender, 'getFullName')) {
                $fromName = trim(strip_tags((string) $sender->getFullName()));
                if ($fromName !== '') {
                    $payload['fromName'] = mb_substr($fromName, 0, 200);
                }
            }
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
            if ($saving) {
                if (!$mapping || (int) $request->customer_id !== (int) $conversation->customer_id) {
                    return response()->json(['message' => 'De gesprekspartner is gewijzigd. Vernieuw de zijbalk en kies opnieuw.'], 409);
                }
                $payload['activityPersonId'] = (int) $request->person_id;
                $payload['activityContext'] = (string) $request->context;
                $saved = $rondo->activityLink($payload);
                if (($saved['status'] ?? '') !== 'saved') {
                    throw new \RuntimeException('activity_link_failed');
                }
                DB::table('rondo_activity_delivery_queue')
                    ->where('conversation_id', $conversation->id)
                    ->where('state', 'retry')
                    ->whereIn('last_error_code', ['needs_link', 'ambiguous', 'no_match'])
                    ->update(['next_attempt_at' => gmdate('Y-m-d H:i:s')]);
                return response()->json($saved);
            }
            $response = $rondo->sidebar($payload);
            if (empty($response['html']) || !is_string($response['html'])) {
                throw new \RuntimeException('sidebar_response_invalid');
            }
            return response()->json(array_merge(['status' => isset($response['status']) ? $response['status'] : 'ok', 'activity_link' => $response['activity_link'] ?? null], $document->render($response['html'])));
        } catch (\Exception $e) {
            return response()->json(['status' => 'unavailable', 'message' => $saving ? 'Opslaan is niet gelukt. Vernieuw de zijbalk en probeer opnieuw.' : 'Rondo is temporarily unavailable.'], 503);
        }
    }
}
