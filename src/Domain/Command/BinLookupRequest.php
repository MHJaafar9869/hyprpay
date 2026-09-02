<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

/**
 * Input DTO for looking up what a card is before charging it.
 *
 * A BIN lookup asks the networks what the credential actually is — brand, funding source,
 * issuer country, whether it is commercial, what it supports — so decisions that must be made
 * *before* authorizing can be made on fact rather than guesswork: routing, surcharging,
 * whether to offer installments, whether 3-D Secure is even supported.
 *
 * The credential can be supplied five ways and exactly one is needed. Prefer a token over a
 * raw PAN wherever you have one: a transient token from Microform or Unified Checkout, or a
 * vault customer / payment-instrument / instrument-identifier id all resolve to the same
 * answer without a card number leaving the vault.
 */
final readonly class BinLookupRequest
{
    /**
     * @param  string|null  $cardNumber  Raw primary account number; prefer a token when one is available
     * @param  string|null  $transientToken  Transient-token JWT from Flex Microform or Unified Checkout
     * @param  string|null  $transientTokenJti  Transient token referenced by its `jti` claim instead of the whole JWT
     * @param  string|null  $customerId  Vault customer whose default instrument is inspected
     * @param  string|null  $paymentInstrumentId  Vault payment-instrument to inspect
     * @param  string|null  $instrumentIdentifierId  Vault instrument-identifier to inspect
     * @param  string|null  $orderReference  Merchant reference for correlating the lookup
     */
    public function __construct(
        public ?string $cardNumber = null,
        public ?string $transientToken = null,
        public ?string $transientTokenJti = null,
        public ?string $customerId = null,
        public ?string $paymentInstrumentId = null,
        public ?string $instrumentIdentifierId = null,
        public ?string $orderReference = null,
    ) {}

    /**
     * Named constructor for the common token-first case: look up whatever the browser just
     * tokenised, so the raw card never reaches your server.
     *
     * @param  string  $transientToken  Transient-token JWT from Microform or Unified Checkout.
     * @param  string|null  $orderReference  Merchant reference for correlating the lookup.
     */
    public static function forTransientToken(string $transientToken, ?string $orderReference = null): self
    {
        return new self(transientToken: $transientToken, orderReference: $orderReference);
    }
}
