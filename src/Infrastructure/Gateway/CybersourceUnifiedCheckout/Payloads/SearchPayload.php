<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

/**
 * Builds the CyberSource Transaction Search (TSS) request body.
 *
 * Used to look up transactions via the Transaction Search Service, typically to reconcile
 * or locate a payment by its merchant reference or transaction attributes.
 */
final class SearchPayload
{
    /**
     * Build the POST /tss/v2/searches request body.
     *
     * Carries the search query string along with fixed paging (offset), sort (newest first),
     * and timezone (UTC) options.
     *
     * @param  string  $query  CyberSource TSS query expression identifying the transactions to match.
     * @param  int  $limit  Maximum number of results to return.
     * @return array<string, mixed>
     */
    public static function build(string $query, int $limit = 1): array
    {
        return [
            'query' => $query,
            'limit' => $limit,
            'offset' => 0,
            'sort' => 'id:desc',
            'timezone' => 'UTC',
        ];
    }
}
