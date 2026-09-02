<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchStatus;

/**
 * Result DTO describing an Account Updater batch — its progress and, once complete, what the
 * card networks changed.
 *
 * The counts are the point of the exercise: {@see $updatedRecords} is how many stored cards the
 * networks actually revised, and fetching the batch report lists those changes token by token
 * so the vault records can be reconciled against your own copies.
 */
final readonly class AccountUpdaterBatch
{
    /**
     * @param  string|null  $batchId  Gateway identifier for the batch, used to poll its status and report
     * @param  AccountUpdaterBatchStatus|null  $status  Normalised processing status
     * @param  string|null  $createdDate  When the batch was submitted (ISO 8601)
     * @param  string|null  $merchantReference  Merchant reference the batch was submitted with
     * @param  string|null  $source  How the batch reached the gateway (e.g. TOKEN_API, SCHEDULER)
     * @param  int  $acceptedRecords  Tokens the gateway accepted into the batch
     * @param  int  $rejectedRecords  Tokens the gateway refused
     * @param  int  $updatedRecords  Cards the networks reported a change for
     * @param  int  $networkResponses  Responses received from the card associations
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $batchId = null,
        public ?AccountUpdaterBatchStatus $status = null,
        public ?string $createdDate = null,
        public ?string $merchantReference = null,
        public ?string $source = null,
        public int $acceptedRecords = 0,
        public int $rejectedRecords = 0,
        public int $updatedRecords = 0,
        public int $networkResponses = 0,
        public array $raw = [],
    ) {}

    /**
     * Whether the batch has finished, so its report can be fetched.
     */
    public function isComplete(): bool
    {
        return $this->status?->isComplete() === true;
    }

    /**
     * Whether the networks changed anything — worth reading the report only when true.
     */
    public function hasUpdates(): bool
    {
        return $this->updatedRecords > 0;
    }
}
