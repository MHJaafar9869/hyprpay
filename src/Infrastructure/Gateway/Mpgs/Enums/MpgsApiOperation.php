<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums;

/**
 * The `apiOperation` values MPGS accepts in a session or transaction request body.
 *
 * A single source of truth for the operation strings the payload builders emit, so the
 * literal values are declared once rather than scattered across the driver.
 */
enum MpgsApiOperation: string
{
    case InitiateCheckout = 'INITIATE_CHECKOUT';
    case Pay = 'PAY';
    case Authorize = 'AUTHORIZE';
    case Capture = 'CAPTURE';
    case Refund = 'REFUND';
    case Void = 'VOID';
    case Verify = 'VERIFY';
    case InitiateAuthentication = 'INITIATE_AUTHENTICATION';
    case AuthenticatePayer = 'AUTHENTICATE_PAYER';
}
