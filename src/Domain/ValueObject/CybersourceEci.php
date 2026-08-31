<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

/**
 * The raw Electronic Commerce Indicator (ECI) returned by a CyberSource 3-D Secure result.
 *
 * The ECI reports the authentication outcome of a payer-authentication (3DS) check and, with
 * it, whether the liability shift applies. CyberSource normalises the outcome across card
 * networks in the `eciRaw` field, so the decision keys on that raw value rather than the
 * network-specific `eci`/`ucafCollectionIndicator` fields — a fully authenticated Mastercard
 * result is `02` while Visa, American Express, JCB, Diners Club and Discover use `05`:
 *
 *  - `02` / `05` — fully authenticated (liability shift)
 *  - `01` / `06` — authentication attempted, not completed (no liability shift)
 *  - `00` / `07` — not authenticated (failed, or no 3DS performed)
 *
 * The value is zero-padded to two digits and resolved from `eciRaw`, falling back to `eci`,
 * so the same classification holds regardless of which field the response populates.
 */
final readonly class CybersourceEci
{
    /**
     * Raw ECIs indicating a fully authenticated 3-D Secure result (liability shift applies).
     *
     * @var list<string>
     */
    public const FULLY_AUTHENTICATED = ['02', '05'];

    /**
     * Raw ECIs indicating authentication was only attempted, not completed.
     *
     * @var list<string>
     */
    public const ATTEMPTED = ['01', '06'];

    /**
     * @param  string  $value  Zero-padded two-digit raw ECI (e.g. "05", "06", "00").
     */
    private function __construct(public string $value) {}

    /**
     * Resolve the ECI from a CyberSource `consumerAuthenticationInformation` block.
     *
     * Prefers the network-normalised `eciRaw`, falling back to `eci`, and returns null when
     * neither is present (e.g. a pending step-up challenge that has no final ECI yet).
     *
     * @param  array<string, mixed>  $consumerAuthenticationInformation
     */
    public static function fromConsumerAuthentication(array $consumerAuthenticationInformation): ?self
    {
        return self::fromRaw($consumerAuthenticationInformation['eciRaw'] ?? $consumerAuthenticationInformation['eci'] ?? null);
    }

    /**
     * Build from a raw ECI value, zero-padding it to two digits, or null when it is absent.
     */
    public static function fromRaw(mixed $eci): ?self
    {
        if (! is_string($eci) && ! is_int($eci)) {
            return null;
        }

        $value = (string) $eci;

        if ($value === '') {
            return null;
        }

        return new self(str_pad($value, 2, '0', STR_PAD_LEFT));
    }

    /**
     * Whether the cardholder was fully authenticated and the liability shift applies.
     */
    public function isFullyAuthenticated(): bool
    {
        return in_array($this->value, self::FULLY_AUTHENTICATED, true);
    }

    /**
     * Whether authentication was only attempted (recorded but not completed).
     */
    public function isAttempted(): bool
    {
        return in_array($this->value, self::ATTEMPTED, true);
    }

    /**
     * Whether the ECI represents no successful authentication (failed or none performed).
     */
    public function isNotAuthenticated(): bool
    {
        return ! $this->isFullyAuthenticated() && ! $this->isAttempted();
    }

    /**
     * Coarse classification of the outcome, for logging and event payloads.
     *
     * @return 'fully_authenticated'|'attempted'|'not_authenticated'
     */
    public function outcome(): string
    {
        return match (true) {
            $this->isFullyAuthenticated() => 'fully_authenticated',
            $this->isAttempted() => 'attempted',
            default => 'not_authenticated',
        };
    }
}
