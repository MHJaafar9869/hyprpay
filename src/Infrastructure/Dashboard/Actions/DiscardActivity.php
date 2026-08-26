<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Actions;

use Hyprpay\Payments\Domain\Contract\RecordsPaymentActivity;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * No-op record action bound when the dashboard's activity store is disabled or set to "null".
 *
 * Lets the recording listener stay a no-op cost without any null-checks at the call site:
 * records handed to it are simply discarded.
 */
final readonly class DiscardActivity implements RecordsPaymentActivity
{
    public function record(PaymentActivityRecord $record): void {}
}
