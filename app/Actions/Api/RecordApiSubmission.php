<?php

namespace App\Actions\Api;

use App\Data\Api\SubmittedEntry;
use App\Models\ApiSubmission;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an offline entry from the mobile app exactly once, however often it is sent.
 *
 * The phone gives each entry a UUID and sends it with every attempt. The document and the
 * api_submissions row holding that key are written in ONE database transaction, and the key is
 * unique — so a key exists exactly when its document does:
 *
 *  - the key is new → the entry is recorded and the key stored with it;
 *  - the key was stored for this same entry → nothing is recorded again; the caller replays
 *    the document it already became;
 *  - the key was stored for a different entry, user or job → refused (422);
 *  - two copies arrive at the same moment → the second loses on the unique index, its whole
 *    transaction (document included) rolls back, and it is told to send again (409), which
 *    then replays.
 *
 * A refused entry (not enough stock, a closed period…) rolls back its key too, so it can be
 * corrected on the phone and sent again under the same key. No business rule lives here —
 * the callback runs the same actions as the website and simple mode.
 */
class RecordApiSubmission
{
    /**
     * The document an earlier sending of this same entry became, or null when it is new.
     *
     * @throws ValidationException when the key was already used for something else
     */
    public function previous(SubmittedEntry $entry): ?Model
    {
        $existing = ApiSubmission::query()->where('idempotency_key', $entry->idempotencyKey)->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->user_id !== $entry->userId
            || $existing->operation !== $entry->operation
            || $existing->request_hash !== $entry->requestHash) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('This key was already used for a different entry. Give every entry its own key.'),
            ]);
        }

        return $existing->document;
    }

    /**
     * @template TDocument of Model
     *
     * @param  Closure(): TDocument  $record
     * @return TDocument
     */
    public function handle(SubmittedEntry $entry, Closure $record): Model
    {
        try {
            return DB::transaction(function () use ($entry, $record): Model {
                $document = $record();

                ApiSubmission::query()->create([
                    'idempotency_key' => $entry->idempotencyKey,
                    'user_id' => $entry->userId,
                    'personal_access_token_id' => $entry->tokenId,
                    'device_name' => $entry->deviceName,
                    'operation' => $entry->operation,
                    'request_hash' => $entry->requestHash,
                    'document_type' => $document->getMorphClass(),
                    'document_id' => $document->getKey(),
                    'recorded_at' => $entry->recordedAt,
                ]);

                return $document;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! ApiSubmission::query()->where('idempotency_key', $entry->idempotencyKey)->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'status' => __('This entry has just been recorded by another attempt. Send it again to get the result.'),
            ]);
        }
    }
}
