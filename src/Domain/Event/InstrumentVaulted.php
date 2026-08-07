<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;

/**
 * Emitted after a payment instrument is tokenized (vaulted) for later reuse.
 */
final readonly class InstrumentVaulted implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that vaulted the instrument.
     * @param  string|null  $customerReference  Merchant customer reference the instrument belongs to.
     * @param  VaultedInstrument  $result  The stored instrument identifiers (token, customer id).
     */
    public function __construct(
        public GatewayName $gateway,
        public ?string $customerReference,
        public VaultedInstrument $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
