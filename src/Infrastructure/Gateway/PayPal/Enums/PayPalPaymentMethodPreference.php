<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

/**
 * PayPal `experience_context.payment_method_preference` — which funding sources are allowed.
 *
 * {@see ImmediatePaymentRequired} restricts checkout to instantly-settling methods (so the
 * capture is final, not pending), while {@see Unrestricted} accepts any method the buyer
 * has, including ones that may complete asynchronously (e.g. eChecks).
 */
enum PayPalPaymentMethodPreference: string
{
    case Unrestricted = 'UNRESTRICTED';
    case ImmediatePaymentRequired = 'IMMEDIATE_PAYMENT_REQUIRED';
}
