<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO returned by a 3-D Secure enrol or validate operation.
 *
 * Reports whether authentication succeeded, its status, and — when the issuer demands
 * a challenge — the step-up URL and access token needed to render it, along with the
 * normalised cryptogram fields to carry into the subsequent charge.
 */
final readonly class PayerAuthResult
{
    /**
     * @param  bool  $success  Whether the authentication step completed successfully
     * @param  string  $status  Gateway authentication status (e.g. AUTHENTICATION_SUCCESSFUL, PENDING_AUTHENTICATION)
     * @param  string|null  $stepUpUrl  Issuer challenge (step-up) URL to redirect the payer to, when a challenge is required
     * @param  string|null  $accessToken  JWT posted to the step-up URL to launch the challenge
     * @param  string|null  $authenticationTransactionId  Identifier used to validate the authentication after a challenge
     * @param  array<string, mixed>  $consumerAuthenticationInformation  Normalised 3DS cryptogram fields
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public string $status,
        public ?string $stepUpUrl = null,
        public ?string $accessToken = null,
        public ?string $authenticationTransactionId = null,
        public array $consumerAuthenticationInformation = [],
        public array $raw = [],
    ) {}

    /**
     * Determine whether the issuer requires an interactive 3DS challenge.
     *
     * Returns true when a step-up URL is present, meaning the payer must complete the
     * challenge flow before the charge can proceed.
     */
    public function requiresChallenge(): bool
    {
        return filled($this->stepUpUrl);
    }
}
