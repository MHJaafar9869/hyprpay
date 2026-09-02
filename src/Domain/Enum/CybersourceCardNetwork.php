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
     * CyberSource's numeric card-type codes, as returned by BIN lookup, the vault, and payment
     * responses. Only the codes this enum has a case for are listed; the rest resolve to null.
     *
     * @var array<string, string>
     */
    private const CODES = [
        '001' => 'VISA',
        '002' => 'MASTERCARD',
        '003' => 'AMEX',
        '004' => 'DISCOVER',
        '005' => 'DINERSCLUB',
        '007' => 'JCB',
        '024' => 'MAESTRO',
        '036' => 'CARTESBANCAIRES',
        '040' => 'UATP',
        '042' => 'MAESTRO',
        '044' => 'KCP',
        '046' => 'JCREW',
        '054' => 'ELO',
        '058' => 'CARNET',
        '060' => 'MADA',
        '062' => 'CUP',
        '067' => 'MEEZA',
        '068' => 'PAYPAK',
        '070' => 'EFTPOS',
        '081' => 'JAYWAN',
    ];

    /**
     * Brand names that do not match a case's backing value once punctuation and spacing are
     * stripped — the networks and the several CyberSource services do not spell them alike.
     *
     * @var array<string, string>
     */
    private const NAME_ALIASES = [
        'AMERICANEXPRESS' => 'AMEX',
        'DINERS' => 'DINERSCLUB',
        'CARTEBANCAIRE' => 'CARTESBANCAIRES',
        'CARTESBANCAIRE' => 'CARTESBANCAIRES',
        'CARTEBLANCHE' => 'CARTESBANCAIRES',
        'CHINAUNIONPAY' => 'CUP',
        'UNIONPAY' => 'CUP',
        'VISAELECTRON' => 'VISA',
    ];

    /**
     * Resolve a CyberSource numeric card-type code (e.g. `001`) to its network.
     *
     * Returns null for a code this enum does not model — an unsupported or newly-published
     * network — which is not an error.
     *
     * @param  string|null  $code  Three-digit card-type code from a BIN lookup, vault record, or payment response.
     */
    public static function fromCyberSourceCode(?string $code): ?self
    {
        $value = self::CODES[(string) $code] ?? null;

        return $value === null ? null : self::from($value);
    }

    /**
     * Resolve a brand name to its network, tolerating the spelling differences between services
     * (`VISA`, `visa`, `AMERICAN EXPRESS`, `Diners Club`, `China Union Pay`).
     *
     * @param  string|null  $name  Brand name as some part of the gateway spelled it.
     */
    public static function fromBrandName(?string $name): ?self
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $name));

        if ($normalised === '') {
            return null;
        }

        return self::tryFrom(self::NAME_ALIASES[$normalised] ?? $normalised);
    }

    /**
     * Resolve either representation — a numeric code or a brand name — to one network.
     *
     * The card brand reaches the SDK in three shapes depending on where it came from: a code
     * from BIN lookup and the vault, a lowercase name from a verified orchestrated result, and
     * an uppercase name from BIN lookup's brand field. This collapses all of them, so a caller
     * branching on the network (to price an offer, say) writes one match rather than three.
     *
     * @param  string|null  $value  A card-type code or a brand name.
     */
    public static function resolve(?string $value): ?self
    {
        return self::fromCyberSourceCode($value) ?? self::fromBrandName($value);
    }

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
