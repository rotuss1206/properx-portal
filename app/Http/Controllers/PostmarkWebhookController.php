<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Models\ReminderLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Postmark delivery, bounce and spam complaint webhook (Spec §3.4): updates the
 * reminder log entry referenced by the "reminder_log_id" metadata and logs bounces
 * and complaints. Always answers 200 for authenticated calls so Postmark stops retrying.
 */
class PostmarkWebhookController extends Controller
{
    /**
     * A later event never downgrades a worse outcome (e.g. Delivery after a SpamComplaint).
     */
    private const SEVERITY = [
        DeliveryStatus::Sent->value => 0,
        DeliveryStatus::Delivered->value => 1,
        DeliveryStatus::Bounced->value => 2,
        DeliveryStatus::Complained->value => 3,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $recordType = (string) $request->input('RecordType');
        $status = match ($recordType) {
            'Delivery' => DeliveryStatus::Delivered,
            'Bounce' => DeliveryStatus::Bounced,
            'SpamComplaint' => DeliveryStatus::Complained,
            default => null,
        };

        if ($status !== DeliveryStatus::Delivered && $status !== null) {
            Log::warning("Postmark {$recordType}", [
                'message_id' => $request->input('MessageID'),
                'type' => $request->input('Type'),
                'recipient' => $request->input('Email') ?? $request->input('Recipient'),
                'description' => $request->input('Description'),
                'tag' => $request->input('Tag'),
            ]);
        }

        $log = $status ? ReminderLog::find($request->input('Metadata.reminder_log_id')) : null;

        if ($log !== null && $this->isWorse($status, $log->delivery_status)) {
            $log->update(['delivery_status' => $status]);
        }

        return response()->json(['handled' => $log !== null]);
    }

    private function isWorse(DeliveryStatus $new, DeliveryStatus $current): bool
    {
        return (self::SEVERITY[$new->value] ?? 0) > (self::SEVERITY[$current->value] ?? 0);
    }
}
