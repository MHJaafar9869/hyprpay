<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Which family of report definitions to resolve against.
 *
 * CyberSource publishes the same reporting surface three ways, and a report definition name is
 * only meaningful within one of them: `Custom` is the modern, field-selectable set (the gateway's
 * default), while `Standard` and `Classic` are the fixed legacy layouts. Asking for a definition
 * under the wrong type is why a name that plainly exists can come back not found.
 */
enum ReportSubscriptionType: string
{
    case Custom = 'CUSTOM';
    case Standard = 'STANDARD';
    case Classic = 'CLASSIC';
}
