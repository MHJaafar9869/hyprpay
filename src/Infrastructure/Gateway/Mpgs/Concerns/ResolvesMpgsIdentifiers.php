<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns;

use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Resolves the MPGS order and transaction identifiers a request maps to.
 *
 * MPGS keys every payment on a merchant-assigned order id plus a per-order transaction
 * id, so the SDK's single-id request DTOs are mapped consistently here: the order comes
 * from the request's order reference, and a newly-created transaction reuses the
 * idempotency key (re-PUTting the same order+transaction id is MPGS's native idempotency).
 * Missing identifiers are generated for order-creating operations and rejected for
 * operations that must target an existing order.
 */
trait ResolvesMpgsIdentifiers
{
    /**
     * Resolve the order id for an order-creating operation, generating one when the
     * request carries no order reference.
     *
     * @param  string|null  $orderReference  Merchant order reference from the request, if any.
     */
    private function orderId(?string $orderReference): string
    {
        $reference = Value::string($orderReference);

        return $reference !== '' ? $reference : $this->generatedIdentifier();
    }

    /**
     * Resolve the order id for an operation that must reference an existing order,
     * rejecting the call when no order reference is supplied.
     *
     * @param  string|null  $orderReference  Merchant order reference from the request, if any.
     * @param  string  $operation  Operation name used in the error message.
     *
     * @throws GatewayRequestException When the order reference is missing.
     */
    private function requireOrderId(?string $orderReference, string $operation): string
    {
        $reference = Value::string($orderReference);

        if ($reference === '') {
            throw new GatewayRequestException(
                status: 0,
                responseBody: '',
                message: "MPGS {$operation} requires an order reference to identify the order.",
            );
        }

        return $reference;
    }

    /**
     * Resolve the id for a newly-created transaction, reusing the idempotency key when
     * present and generating one otherwise.
     *
     * @param  string|null  $idempotencyKey  Idempotency key from the request, if any.
     */
    private function newTransactionId(?string $idempotencyKey): string
    {
        $key = Value::string($idempotencyKey);

        return $key !== '' ? $key : $this->generatedIdentifier();
    }

    /**
     * Generate a unique, URL-safe identifier for an order or transaction.
     */
    private function generatedIdentifier(): string
    {
        return bin2hex(random_bytes(16));
    }
}
