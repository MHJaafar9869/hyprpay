<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Processing status of an Account Updater batch.
 *
 * A batch is accepted, validated, sent to the card associations, and only then reported on —
 * the whole cycle takes hours to days, since it depends on the networks answering. Poll with
 * {@see isInProgress()} rather than expecting a submitted batch to have results immediately.
 */
enum AccountUpdaterBatchStatus: string
{
    case Received = 'RECEIVED';
    case Validated = 'VALIDATED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Declined = 'DECLINED';

    /**
     * Human-readable display name for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Validated => 'Validated',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Declined => 'Declined',
        };
    }

    /**
     * Whether the batch has finished and its report can be fetched.
     */
    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether the batch is still moving through the networks, so a report would be premature.
     */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::Received, self::Validated, self::Processing => true,
            self::Completed, self::Rejected, self::Declined => false,
        };
    }

    /**
     * Whether the batch was refused outright and will produce no updates.
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::Rejected, self::Declined => true,
            self::Received, self::Validated, self::Processing, self::Completed => false,
        };
    }
}
