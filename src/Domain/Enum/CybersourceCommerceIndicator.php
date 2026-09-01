<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

use Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest;

/**
 * Commerce indicator declaring how a CyberSource Recurring Billing subscription was taken.
 *
 * Sent as `processingInformation.commerceIndicator` when creating a subscription; card
 * networks use it when setting discount rates, so it should describe how the cardholder
 * actually authorised the recurring agreement. CyberSource ignores the field entirely when
 * the request carries a `subscriptionInformation.originalTransactionId` (the network
 * transaction id already establishes the series) or when updating a subscription.
 *
 * Not every processor accepts `Recurring` on the zero-dollar authorization CyberSource runs
 * for a subscription that starts on a future date — leave
 * {@see CreateSubscriptionRequest::$commerceIndicator} null to let CyberSource pick, rather
 * than forcing a value the processor rejects.
 */
enum CybersourceCommerceIndicator: string
{
    case Recurring = 'RECURRING';
    case Internet = 'INTERNET';
    case Moto = 'MOTO';
}
