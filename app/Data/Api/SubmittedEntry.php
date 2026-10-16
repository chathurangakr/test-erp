<?php

namespace App\Data\Api;

use Carbon\CarbonInterface;

/**
 * Who sent an offline entry, from which device, under which idempotency key — everything
 * RecordApiSubmission needs besides the entry itself.
 */
final readonly class SubmittedEntry
{
    public function __construct(
        public string $idempotencyKey,
        public string $operation,
        public string $requestHash,
        public int $userId,
        public ?int $tokenId,
        public ?string $deviceName,
        public ?CarbonInterface $recordedAt,
    ) {}
}
