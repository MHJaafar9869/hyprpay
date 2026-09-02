<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO describing the outcome of a Visa Bank Account Validation (BAVS) check.
 *
 * Two codes describe the outcome and they answer different questions. {@see $resultCode} is
 * the verdict on the account — only `0` (`00`) is documented as a pass, so
 * {@see isValid()} treats every other value as "not validated" rather than guessing at the
 * meaning of `04`, `98`, and `99`. {@see $rawValidationCode} says whether the check could be
 * performed at all: `-1` is an unknown error and `-2` means the service was unavailable, both
 * of which are *inconclusive* rather than a failed account — retry those instead of rejecting
 * the customer's bank details.
 */
final readonly class BankAccountValidationResult
{
    /**
     * Result code the service returns for an account that validated successfully.
     */
    public const RESULT_VALID = 0;

    /**
     * Raw validation code meaning the check could not be performed for an unknown reason.
     */
    public const RAW_UNKNOWN_ERROR = -1;

    /**
     * Raw validation code meaning the validation service itself was unavailable.
     */
    public const RAW_SERVICE_UNAVAILABLE = -2;

    /**
     * @param  int|null  $resultCode  Verdict on the account; `0` is a pass, other documented values are `4`, `98`, `99`
     * @param  int|null  $rawValidationCode  Whether the check ran: `-1` unknown error, `-2` service unavailable, `12`-`16` validation results
     * @param  string|null  $resultMessage  Human-readable result message from the service
     * @param  string|null  $requestId  Gateway identifier for the validation request
     * @param  string|null  $submitTimeUtc  When the request was processed (UTC)
     * @param  string|null  $orderReference  Merchant reference the validation was sent with
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?int $resultCode = null,
        public ?int $rawValidationCode = null,
        public ?string $resultMessage = null,
        public ?string $requestId = null,
        public ?string $submitTimeUtc = null,
        public ?string $orderReference = null,
        public array $raw = [],
    ) {}

    /**
     * Whether the account validated successfully and an ACH debit may proceed.
     *
     * Deliberately strict: only the documented pass code counts, so an inconclusive or
     * unrecognised outcome never reads as a validated account.
     */
    public function isValid(): bool
    {
        return $this->resultCode === self::RESULT_VALID;
    }

    /**
     * Whether the check could not be completed — the service was unavailable or failed for an
     * unknown reason — as opposed to returning a verdict on the account.
     *
     * An inconclusive result should be retried; it is not evidence that the account is bad, so
     * rejecting the customer's details on it would be wrong.
     */
    public function isInconclusive(): bool
    {
        return in_array($this->rawValidationCode, [self::RAW_UNKNOWN_ERROR, self::RAW_SERVICE_UNAVAILABLE], true);
    }
}
