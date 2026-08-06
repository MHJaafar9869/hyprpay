<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Application;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\GatewayException;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

/**
 * Application use-case that reconciles transactions against their gateway.
 *
 * Resolves the driver through the {@see PaymentGatewayFactory} and fetches the
 * authoritative snapshot for each requested id. A failed lookup for one id is
 * captured as a {@see ReconciliationOutcome} error rather than aborting the batch,
 * so every id is always accounted for. Only the SDK's own {@see GatewayException}s
 * are captured; unexpected errors are left to propagate.
 */
final readonly class TransactionReconciler
{
    /**
     * @param  PaymentGatewayFactory  $factory  Builds the gateway driver to reconcile against.
     */
    public function __construct(private PaymentGatewayFactory $factory) {}

    /**
     * Reconcile one or more transaction ids against the given gateway.
     *
     * @param  GatewayName  $gateway  The gateway whose transactions are being reconciled.
     * @param  list<string>  $transactionIds  The gateway transaction ids to look up, in order.
     * @param  GatewayCredentials|null  $credentials  Explicit credentials, or null to resolve them from configuration.
     * @return list<ReconciliationOutcome> One outcome per id, in the order given.
     */
    public function reconcile(GatewayName $gateway, array $transactionIds, ?GatewayCredentials $credentials = null): array
    {
        $driver = $this->factory->make($gateway, $credentials);

        $outcomes = [];

        foreach ($transactionIds as $transactionId) {
            try {
                $outcomes[] = new ReconciliationOutcome($transactionId, $driver->getTransaction($transactionId));
            } catch (GatewayException $exception) {
                $outcomes[] = new ReconciliationOutcome($transactionId, error: $exception->getMessage());
            }
        }

        return $outcomes;
    }
}
