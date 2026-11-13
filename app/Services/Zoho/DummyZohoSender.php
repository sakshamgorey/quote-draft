<?php

namespace App\Services\Zoho;

use App\Models\Draft;
use App\Models\ZohoSubmission;

/**
 * "Sends" a quote request without any network call: records the exact request and
 * a response shaped like Zoho's Insert Records success response, so the hand-off
 * can be demonstrated and audited. One submission per draft (idempotent).
 */
class DummyZohoSender
{
    public function send(Draft $draft, array $request): ZohoSubmission
    {
        if ($existing = $draft->zohoSubmission) {
            return $existing; // a retry never creates a second Zoho quote
        }

        $now = now();
        $id = 'DUMMY-'.str_pad((string) (crc32($draft->ulid) % 10_000_000_000), 13, '0', STR_PAD_LEFT);

        return $draft->zohoSubmission()->create([
            'mode' => 'dummy',
            'method' => $request['method'],
            'url' => $request['url'],
            'request' => $request,
            'response' => [
                'data' => [[
                    'code' => 'SUCCESS',
                    'details' => [
                        'Modified_Time' => $now->toIso8601String(),
                        'Created_Time' => $now->toIso8601String(),
                        'id' => $id,
                    ],
                    'message' => 'record added',
                    'status' => 'success',
                ]],
            ],
            'zoho_record_id' => $id,
            'sent_at' => $now,
        ]);
    }
}
