<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for the PayTabs gateway.
 *
 * Replaces PayTabs' options bag — the richest of the gateways — with named fields: the
 * transaction class, IPN webhook URL, tokenisation level, and the iframe/framed-mode flags,
 * plus the three structured extras modelled as their own value objects: a repeat-billing
 * {@see PaytabsAgreement}, a list of {@see PaytabsSplitPayout} stakeholders, and the invoice
 * {@see PaytabsLineItem} lines.
 */
final readonly class PaytabsCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  string|null  $tranClass  Transaction class override (defaults to `ecom`).
     * @param  string|null  $webhookUrl  Server-to-server IPN callback URL.
     * @param  int|null  $tokenise  Tokenisation format (1–6) to save a reusable card token.
     * @param  bool  $iframe  Render the hosted page inside an iframe instead of redirecting.
     * @param  bool|null  $framedReturnTop  Reload the top window on the framed return.
     * @param  bool|null  $framedReturnParent  Reload the parent window on the framed return.
     * @param  string|null  $framedMessageTarget  HTTPS URL that receives the framed postMessage.
     * @param  PaytabsAgreement|null  $agreement  Repeat-billing agreement to start on checkout.
     * @param  array<int, PaytabsSplitPayout>  $splitPayout  Stakeholders to split settled funds across.
     * @param  array<int, PaytabsLineItem>  $lineItems  Invoice line items.
     */
    public function __construct(
        public ?string $tranClass = null,
        public ?string $webhookUrl = null,
        public ?int $tokenise = null,
        public bool $iframe = false,
        public ?bool $framedReturnTop = null,
        public ?bool $framedReturnParent = null,
        public ?string $framedMessageTarget = null,
        public ?PaytabsAgreement $agreement = null,
        public array $splitPayout = [],
        public array $lineItems = [],
    ) {}

    /**
     * Resolve a request's options to PaytabsCheckoutOptions (typed object or legacy array).
     */
    public static function fromRequest(CheckoutSessionRequest $request): self
    {
        return $request->options instanceof self ? $request->options : self::fromArray($request->optionsArray());
    }

    /**
     * Build the options from a raw PayTabs option-key array (backward compatibility).
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $agreement = Value::array($options['agreement'] ?? null);

        return new self(
            tranClass: Value::nullableString($options['tran_class'] ?? null),
            webhookUrl: Value::nullableString($options['webhook_url'] ?? null),
            tokenise: isset($options['tokenise']) ? Value::int($options['tokenise']) : null,
            iframe: filter_var($options['iframe'] ?? false, FILTER_VALIDATE_BOOLEAN),
            framedReturnTop: self::optionalBool($options, 'framed_return_top'),
            framedReturnParent: self::optionalBool($options, 'framed_return_parent'),
            framedMessageTarget: Value::nullableString($options['framed_message_target'] ?? null),
            agreement: $agreement === [] ? null : PaytabsAgreement::fromArray($agreement),
            splitPayout: array_values(array_map(
                static fn (mixed $stakeholder): PaytabsSplitPayout => PaytabsSplitPayout::fromArray(Value::array($stakeholder)),
                Value::array($options['split_payout'] ?? null),
            )),
            lineItems: array_values(array_map(
                static fn (mixed $item): PaytabsLineItem => PaytabsLineItem::fromArray(Value::array($item)),
                Value::array($options['line_items'] ?? null),
            )),
        );
    }

    /**
     * Render the options as PayTabs' raw option-key array, dropping unset fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'tran_class' => $this->tranClass,
            'webhook_url' => $this->webhookUrl,
            'tokenise' => $this->tokenise,
            'iframe' => $this->iframe ?: null,
            'framed_return_top' => $this->framedReturnTop,
            'framed_return_parent' => $this->framedReturnParent,
            'framed_message_target' => $this->framedMessageTarget,
            'agreement' => $this->agreement?->toArray(),
            'split_payout' => $this->splitPayout === [] ? null : array_map(static fn (PaytabsSplitPayout $s): array => $s->toArray(), $this->splitPayout),
            'line_items' => $this->lineItems === [] ? null : array_map(static fn (PaytabsLineItem $i): array => $i->toArray(), $this->lineItems),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Read an optional boolean option, or null when the key is absent.
     *
     * @param  array<string, mixed>  $options
     */
    private static function optionalBool(array $options, string $key): ?bool
    {
        if (! array_key_exists($key, $options)) {
            return null;
        }

        return filter_var($options[$key], FILTER_VALIDATE_BOOLEAN);
    }
}
