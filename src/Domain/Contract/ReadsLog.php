<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

/**
 * Read seam for the SDK's recent log entries, surfaced on the monitoring dashboard.
 *
 * Implemented by a reader over the SDK's dedicated log channel. Kept PII-safe: the SDK's
 * own channel logs only masked, non-sensitive context.
 */
interface ReadsLog
{
    /**
     * Read the most recent log entries, newest first.
     *
     * @param  int  $limit  The maximum number of entries to return.
     * @return list<array{time: string, level: string, message: string, detail: string}>
     */
    public function recent(int $limit): array;
}
