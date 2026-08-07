<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\WebhookEvent;

/**
 * Emitted after an inbound webhook is verified and parsed.
 *
 * The webhook's {@see WebhookEvent::$verified} flag tells whether the signature checked
 * out; a listener should ignore unverified notifications.
 */
final readonly class WebhookReceived implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that sent the webhook.
     * @param  WebhookEvent  $webhook  The verified and parsed webhook event.
     */
    public function __construct(
        public GatewayName $gateway,
        public WebhookEvent $webhook,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
