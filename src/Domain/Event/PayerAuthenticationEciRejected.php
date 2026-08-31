<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\CybersourceEci;

/**
 * Emitted when a 3-D Secure result is rejected because its ECI is not fully authenticated.
 *
 * The driver enforces that a completed payer authentication carries a fully-authenticated
 * ECI ({@see CybersourceEci::FULLY_AUTHENTICATED}) before
 * the charge is allowed to proceed. When the resolved ECI is an attempted or not-authenticated
 * value, the authentication is marked unsuccessful and this event is dispatched so listeners can
 * alert on, record, or review the rejection. It carries only non-sensitive fields — never the
 * cryptogram (CAVV) or any card data.
 */
final readonly class PayerAuthenticationEciRejected implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that produced the 3-D Secure result.
     * @param  string  $eci  The resolved raw ECI that was rejected, zero-padded (e.g. "06").
     * @param  list<string>  $acceptedEci  The raw ECIs that would have been accepted as fully authenticated.
     * @param  string  $outcome  Classification of the rejected ECI: "attempted" or "not_authenticated".
     * @param  string|null  $authenticationTransactionId  3-D Secure authentication transaction id, for correlation.
     * @param  PaymentStatus  $status  Normalised status the rejection resolves to (always {@see PaymentStatus::Declined}).
     */
    public function __construct(
        public GatewayName $gateway,
        public string $eci,
        public array $acceptedEci,
        public string $outcome,
        public ?string $authenticationTransactionId,
        public PaymentStatus $status,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
