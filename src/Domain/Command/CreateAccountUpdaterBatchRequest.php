<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchType;
use Hyprpay\Payments\Domain\ValueObject\AccountUpdaterToken;

/**
 * Input DTO for submitting vaulted cards to Account Updater.
 *
 * Account Updater asks the card networks whether the cards behind your stored tokens have been
 * reissued, re-dated, or closed, and pushes the answers back into the vault. It is the standing
 * fix for recurring-billing churn: a card that expires or is replaced would otherwise fail every
 * scheduled charge permanently, and the decline only surfaces once the rebill has already failed.
 *
 * Submission is asynchronous — the networks answer over hours or days — so this returns a batch
 * id to poll, not results.
 */
final readonly class CreateAccountUpdaterBatchRequest
{
    /**
     * @param  list<AccountUpdaterToken>  $tokens  Vault tokens to refresh
     * @param  AccountUpdaterBatchType  $type  Which network flow this batch is for; Amex cards must go in a registration batch
     * @param  string|null  $merchantReference  Merchant reference echoed back on the batch, for reconciling it to your own run
     */
    public function __construct(
        public array $tokens,
        public AccountUpdaterBatchType $type = AccountUpdaterBatchType::OneOff,
        public ?string $merchantReference = null,
    ) {}

    /**
     * Named constructor for the common case: a batch of plain token ids with no stored expiry
     * to send alongside them.
     *
     * @param  array<int, string>  $tokenIds  TMS token ids to refresh.
     * @param  AccountUpdaterBatchType  $type  Which network flow this batch is for.
     * @param  string|null  $merchantReference  Merchant reference echoed back on the batch.
     */
    public static function forTokenIds(
        array $tokenIds,
        AccountUpdaterBatchType $type = AccountUpdaterBatchType::OneOff,
        ?string $merchantReference = null,
    ): self {
        return new self(
            tokens: array_values(array_map(
                static fn (string $id): AccountUpdaterToken => new AccountUpdaterToken($id),
                $tokenIds,
            )),
            type: $type,
            merchantReference: $merchantReference,
        );
    }
}
