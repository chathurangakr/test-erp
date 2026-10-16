<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Actions\Api\RecordApiSubmission;
use App\Data\Api\SubmittedEntry;
use App\Enums\Permission;
use App\Models\ApiSubmission;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * The mobile app's offline entries: save AND post in one call, recorded once per
 * Idempotency-Key however often the phone sends it. See docs/API_V1.md §17.
 *
 * Order matters:
 *  1. the key is checked first, and a key already recorded for this same entry replays the
 *     document it became — BEFORE validation, because master data may have changed since
 *     (a product deactivated) and the entry is already safely recorded;
 *  2. only a new entry needs the create permission (the route already required the post
 *     permission, as simple mode does) and the website's own FormRequest;
 *  3. the document and its key are written in one transaction (RecordApiSubmission).
 *
 * Unlike simple mode the date is NOT forced to today: an offline entry keeps the date the
 * work was really done.
 */
trait RecordsOfflineEntries
{
    /**
     * @template TDocument of Model
     *
     * @param  class-string<FormRequest>  $formRequest
     * @param  Closure(array<string, mixed>, int): TDocument  $record
     * @param  Closure(TDocument): JsonResource  $present
     */
    protected function recordOnce(
        Request $request,
        string $operation,
        Permission $create,
        string $formRequest,
        Closure $record,
        Closure $present,
    ): JsonResponse {
        $entry = $this->submittedEntry($request, $operation);
        $submissions = app(RecordApiSubmission::class);

        $previous = $submissions->previous($entry);

        if ($previous !== null) {
            return $present($previous)->response()->setStatusCode(201)->header('Idempotent-Replayed', 'true');
        }

        Gate::authorize($create->value);

        /** @var FormRequest $validated */
        $validated = app($formRequest);
        $data = $validated->validated();

        $document = $submissions->handle($entry, fn (): Model => $record($data, $entry->userId));

        return $present($document)->response()->setStatusCode(201)->header('Idempotent-Replayed', 'false');
    }

    private function submittedEntry(Request $request, string $operation): SubmittedEntry
    {
        Validator::make(
            ['idempotency_key' => $request->header('Idempotency-Key'), 'recorded_at' => $request->input('recorded_at')],
            ['idempotency_key' => ['required', 'uuid'], 'recorded_at' => ['nullable', 'date']],
            ['idempotency_key.required' => __('Send an Idempotency-Key header: a UUID the phone gave this entry when it was recorded.')],
        )->validate();

        $token = $request->user()->currentAccessToken();
        $isStoredToken = $token instanceof Model && $token->exists;

        return new SubmittedEntry(
            idempotencyKey: mb_strtolower((string) $request->header('Idempotency-Key')),
            operation: $operation,
            // Hashed as received, before any FormRequest fills in defaults.
            requestHash: ApiSubmission::hashOf($request->all()),
            userId: $request->user()->id,
            tokenId: $isStoredToken ? $token->getKey() : null,
            deviceName: $isStoredToken ? $token->getAttribute('name') : null,
            recordedAt: filled($request->input('recorded_at')) ? Carbon::parse($request->input('recorded_at'))->utc() : null,
        );
    }
}
