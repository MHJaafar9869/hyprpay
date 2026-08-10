<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `VOID` transaction request body.
 *
 * MPGS voids (and reverses authorizations) by targeting the prior transaction to undo via
 * `transaction.targetTransactionId`. Both the void and authorization-reversal operations
 * map to the same MPGS VOID request, differing only in the SDK status they resolve to.
 */
final class VoidPayload
{
    /**
     * Build the VOID request body targeting the transaction to void.
     *
     * @param  VoidRequest  $request  Void inputs (id of the transaction to void).
     * @return array<string, mixed>
     */
    public static function build(VoidRequest $request): array
    {
        return self::forTarget($request->transactionId);
    }

    /**
     * Build the VOID request body for an authorization reversal, targeting the
     * authorization to release.
     *
     * @param  ReversalRequest  $request  Reversal inputs (id of the authorization to reverse).
     * @return array<string, mixed>
     */
    public static function forReversal(ReversalRequest $request): array
    {
        return self::forTarget($request->transactionId);
    }

    /**
     * Build a VOID request body targeting the given prior transaction id.
     *
     * @return array<string, mixed>
     */
    private static function forTarget(string $targetTransactionId): array
    {
        return [
            'apiOperation' => MpgsApiOperation::Void->value,
            'transaction' => ['targetTransactionId' => $targetTransactionId],
        ];
    }
}
