<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Tests\Support;

use Hyprpay\Payments\Domain\Contract\PaymentActivityRepository;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * In-memory PaymentActivityRepository that keeps recorded activity for assertions.
 *
 * Prepends records so reads are newest-first, mirroring the production cache adapter.
 */
final class RecordingActivityRepository implements PaymentActivityRepository
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

    public function clear(): void
    {
        $this->records = [];
    }
}
