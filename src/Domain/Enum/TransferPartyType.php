<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Whether a party to a funds transfer is a person or an organisation.
 *
 * The networks treat the two differently for identification purposes: an individual is identified
 * by name and date of birth, an organisation by its registered details.
 */
enum TransferPartyType: string
{
    case Individual = 'I';
    case Business = 'B';
}
