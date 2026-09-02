<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums;

/**
 * CyberSource REST API resource paths used by the Unified Checkout gateway.
 *
 * Each case is the URL path (relative to the credential host) for a CyberSource
 * service — Unified Checkout capture contexts, payment processing, token
 * management (TMS), payer authentication (risk), recurring billing (RBS), transaction
 * search (TSS), reporting, Account Updater, BIN lookup, notification subscriptions, funds
 * transfer (OCT/AFT), and Visa Bank Account Validation (BAVS). Cases with an `{id}` placeholder
 * are resolved via path().
 */
enum CybersourceEndpoint: string
{
    case CaptureContexts = '/up/v1/capture-contexts';
    case Sessions = '/uc/v1/sessions';
    case MicroformSessions = '/microform/v2/sessions';
    case Payments = '/pts/v2/payments';
    case Captures = '/pts/v2/payments/{id}/captures';
    case Refunds = '/pts/v2/payments/{id}/refunds';
    case Voids = '/pts/v2/payments/{id}/voids';
    case Reversals = '/pts/v2/payments/{id}/reversals';
    case Credits = '/pts/v2/credits';
    case CaptureRefunds = '/pts/v2/captures/{id}/refunds';
    case CaptureVoids = '/pts/v2/captures/{id}/voids';
    case CreditVoids = '/pts/v2/credits/{id}/voids';
    case RefundVoids = '/pts/v2/refunds/{id}/voids';
    case TimeoutVoids = '/pts/v2/voids';
    case TimeoutReversals = '/pts/v2/reversals';
    case RefreshPaymentStatus = '/pts/v2/refresh-payment-status/{id}';
    case PushFundsTransfer = '/pts/v1/push-funds-transfer';
    case PullFundsTransfer = '/pts/v1/pull-funds-transfer';
    case PullFundsRefund = '/pts/v1/pull-funds-transfer/{id}/refund';
    case PullFundsReversal = '/pts/v1/pull-funds-transfer/{id}/reversal';
    case Payouts = '/pts/v2/payouts';
    case PayoutFxRates = '/pts/v2/payouts/fx-rates';
    case PayoutTransactionQuery = '/pts/v2/payouts/transaction-query/{id}';
    case CurrencyConversion = '/vas/v1/currencyconversion';
    case AuthenticationSetups = '/risk/v1/authentication-setups';
    case Authentications = '/risk/v1/authentications';
    case AuthenticationResults = '/risk/v1/authentication-results';
    case InstrumentIdentifiers = '/tms/v1/instrumentidentifiers';
    case InstrumentIdentifier = '/tms/v1/instrumentidentifiers/{id}';
    case InstrumentIdentifierPaymentInstruments = '/tms/v1/instrumentidentifiers/{id}/paymentinstruments';
    case Customers = '/tms/v2/customers';
    case Customer = '/tms/v2/customers/{id}';
    case CustomerPaymentInstruments = '/tms/v2/customers/{id}/payment-instruments';
    case CustomerPaymentInstrument = '/tms/v2/customers/{id}/payment-instruments/{childId}';
    case Plans = '/rbs/v1/plans';
    case Plan = '/rbs/v1/plans/{id}';
    case PlanCode = '/rbs/v1/plans/code';
    case PlanActivate = '/rbs/v1/plans/{id}/activate';
    case PlanDeactivate = '/rbs/v1/plans/{id}/deactivate';
    case Subscriptions = '/rbs/v1/subscriptions';
    case Subscription = '/rbs/v1/subscriptions/{id}';
    case SubscriptionCancel = '/rbs/v1/subscriptions/{id}/cancel';
    case SubscriptionSuspend = '/rbs/v1/subscriptions/{id}/suspend';
    case SubscriptionActivate = '/rbs/v1/subscriptions/{id}/activate';
    case SubscriptionPayments = '/rbs/v1/subscriptions/{id}/payments';
    case TransactionDetails = '/tss/v2/transactions/{id}';
    case TransactionSearch = '/tss/v2/searches';
    case Reports = '/reporting/v3/reports';
    case Report = '/reporting/v3/reports/{id}';
    case ReportDownloads = '/reporting/v3/report-downloads';
    case ReportDefinitions = '/reporting/v3/report-definitions';
    case ReportDefinition = '/reporting/v3/report-definitions/{id}';
    case ReportSubscriptions = '/reporting/v3/report-subscriptions';
    case ReportSubscription = '/reporting/v3/report-subscriptions/{id}';
    case AccountValidations = '/bavs/v1/account-validations';
    case BinLookups = '/bin/v1/binlookup';
    case Webhooks = '/notification-subscriptions/v2/webhooks';
    case Webhook = '/notification-subscriptions/v2/webhooks/{id}';
    case WebhookStatus = '/notification-subscriptions/v2/webhooks/{id}/status';
    case WebhookTest = '/notification-subscriptions/v1/webhooks/{id}';
    case WebhookProducts = '/notification-subscriptions/v2/products/{id}';
    case WebhookSymmetricKeys = '/kms/egress/v2/keys-sym';
    case AccountUpdaterBatches = '/accountupdater/v1/batches';
    case AccountUpdaterBatchStatus = '/accountupdater/v1/batches/{id}/status';
    case AccountUpdaterBatchReport = '/accountupdater/v1/batches/{id}/report';

    /**
     * Returns the concrete request path, substituting the `{id}` placeholder with the given
     * resource identifier (payment/customer/subscription id) and `{childId}` with the id of a
     * resource nested under it (a customer's payment instrument). Endpoints without a given
     * placeholder ignore that argument.
     *
     * @param  string  $id  Resource id to interpolate into the path template.
     * @param  string  $childId  Nested resource id, for the two-level paths.
     */
    public function path(string $id = '', string $childId = ''): string
    {
        return str_replace(['{id}', '{childId}'], [$id, $childId], $this->value);
    }
}
