<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Tests\Support;

use Hyprpay\Payments\Domain\Contract\ReadsPaymentActivity;
use Hyprpay\Payments\Domain\Contract\RecordsPaymentActivity;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * In-memory record action + read query pair for assertions.
 *
 * Prepends recorded records so reads are newest-first, mirroring the production stores.
 */
final class InMemoryPaymentActivity implements ReadsPaymentActivity, RecordsPaymentActivity
{
    /**
     * @var list<PaymentActivityRecord>
     */
    public array $records = [];

    public function record(PaymentActivityRecord $record): void
    {
        array_unshift($this->records, $record);
    }

    public function recent(int $limit): array
    {
        return array_slice($this->records, 0, $limit);
    }

    public function lifecycle(string $reference): array
    {
        $matching = array_filter(
            $this->records,
            static fn (PaymentActivityRecord $record): bool => $record->orderReference === $reference || $record->transactionId === $reference,
        );

        return array_reverse(array_values($matching));
    }
}
