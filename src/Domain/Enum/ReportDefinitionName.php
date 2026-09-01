<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

use Hyprpay\Payments\Domain\Command\CreateReportRequest;
use Hyprpay\Payments\Domain\Result\ReportDefinition;

/**
 * The report types CyberSource documents as standard — the value passed as
 * `reportDefinitionName` when creating a report or a report subscription.
 *
 * These are the definitions published in the Reporting Developer Guide. A merchant's actual
 * catalogue can differ: entitlements, portfolio configuration, and the
 * {@see ReportSubscriptionType} family all affect which definitions resolve, and a merchant may
 * hold custom definitions that are not listed here. That is why
 * {@see CreateReportRequest::$definitionName} accepts a plain string alongside this enum —
 * the enum gives typo-safety for the documented set without locking out a name a merchant is
 * legitimately entitled to. Confirm what an account can actually run with
 * `listReportDefinitions()`, and read a definition's selectable fields from
 * {@see ReportDefinition::fieldNames()}.
 */
enum ReportDefinitionName: string
{
    case TransactionRequest = 'TransactionRequestClass';
    case PaymentBatchDetail = 'PaymentBatchDetailClass';
    case ExceptionDetail = 'ExceptionDetailClass';
    case ProcessorSettlementDetail = 'ProcessorSettlementDetailClass';
    case ProcessorEventsDetail = 'ProcessorEventsDetailClass';
    case FundingDetail = 'FundingDetailClass';
    case AgingDetail = 'AgingDetailClass';
    case ChargebackAndRetrievalDetail = 'ChargebackAndRetrievalDetailClass';
    case DepositDetail = 'DepositDetailClass';
    case FeeDetail = 'FeeDetailClass';
    case InvoiceSummary = 'InvoiceSummaryClass';
    case PayerAuthDetail = 'PayerAuthDetailClass';
    case ConversionDetail = 'ConversionDetailClass';
    case SubscriptionDetail = 'SubscriptionDetailClass';
    case JpTransactionDetail = 'JPTransactionDetailClass';
    case ServiceFeeDetail = 'ServiceFeeDetailClass';
    case GatewayTransactionRequest = 'GatewayTransactionRequestClass';
    case DecisionManagerEventDetail = 'DecisionManagerEventDetailClass';
    case RecurringBillingDetail = 'RecurringBillingDetailClass';

    /**
     * The report's documented title, for showing the choice to a human.
     */
    public function label(): string
    {
        return match ($this) {
            self::TransactionRequest => 'Transaction Request Report',
            self::PaymentBatchDetail => 'Payment Batch Detail Report',
            self::ExceptionDetail => 'Transaction Exception Detail Report',
            self::ProcessorSettlementDetail => 'Processor Settlement Detail Report',
            self::ProcessorEventsDetail => 'Processor Events Detail Report',
            self::FundingDetail => 'Funding Detail Report',
            self::AgingDetail => 'Aging Detail Report',
            self::ChargebackAndRetrievalDetail => 'Chargeback And Retrieval Detail Report',
            self::DepositDetail => 'Deposit Detail Report',
            self::FeeDetail => 'Fee Detail Report',
            self::InvoiceSummary => 'Invoice Summary Report',
            self::PayerAuthDetail => 'Payer Authentication Detail Report',
            self::ConversionDetail => 'Conversion Detail Report',
            self::SubscriptionDetail => 'Subscription Detail Report',
            self::JpTransactionDetail => 'JP Transaction Detail Report',
            self::ServiceFeeDetail => 'Service Fee Detail Report',
            self::GatewayTransactionRequest => 'Gateway Transaction Request Report',
            self::DecisionManagerEventDetail => 'Decision Manager Event Detail Report',
            self::RecurringBillingDetail => 'Recurring Billing Details Report',
        };
    }

    /**
     * Resolve a definition name to its case, or null when it is one this enum does not model —
     * a custom or newly-published report, which is not an error.
     *
     * @param  ReportDefinitionName|string|null  $name  Name to resolve.
     */
    public static function resolve(self|string|null $name): ?self
    {
        if ($name instanceof self) {
            return $name;
        }

        return $name === null ? null : self::tryFrom($name);
    }

    /**
     * Render a definition name as the string CyberSource expects, accepting either form.
     *
     * @param  ReportDefinitionName|string  $name  Enum case or a raw definition name.
     */
    public static function toValue(self|string $name): string
    {
        return $name instanceof self ? $name->value : $name;
    }

    /**
     * Returns every documented definition name, e.g. to populate a report-type picker.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
