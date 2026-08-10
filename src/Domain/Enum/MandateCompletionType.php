<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Orchestration mode requested from the CyberSource Unified Checkout v1 widget.
 *
 * Adding a completeMandate to the capture context switches the embedded widget from
 * the manual transient-token flow to the orchestrated (autoProcessing) flow, where it
 * runs Decision Manager, 3-D Secure, authorization and TMS tokenization client-side and
 * returns a signed completed-payment result JWT. The type selects the financial outcome:
 * Capture settles the payment (sale), Auth places an authorization hold only.
 */
enum MandateCompletionType: string
{
    case Capture = 'CAPTURE';
    case Auth = 'AUTH';
}
