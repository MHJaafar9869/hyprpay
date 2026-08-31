<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Card networks (`allowedCardNetworks`) accepted by a CyberSource Unified Checkout or Flex
 * Microform capture context — the exact set CyberSource lists as valid values when launching
 * the widget or Microform card fields.
 *
 * Availability of any given network depends on the merchant's processor and region
 * entitlements; listing a network the account is not set up for is simply ignored by CyberSource.
 */
enum CybersourceCardNetwork: string
{
    case Visa = 'VISA';
    case Mastercard = 'MASTERCARD';
    case Amex = 'AMEX';
    case Carnet = 'CARNET';
    case CartesBancaires = 'CARTESBANCAIRES';
    case Cup = 'CUP';
    case DinersClub = 'DINERSCLUB';
    case Discover = 'DISCOVER';
    case Eftpos = 'EFTPOS';
    case Elo = 'ELO';
    case Jaywan = 'JAYWAN';
    case Jcb = 'JCB';
    case Jcrew = 'JCREW';
    case Kcp = 'KCP';
    case Mada = 'MADA';
    case Maestro = 'MAESTRO';
    case Meeza = 'MEEZA';
    case Paypak = 'PAYPAK';
    case Uatp = 'UATP';

    /**
     * Returns every card-network string value, e.g. for populating the capture context's
     * `allowedCardNetworks` list.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
