<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * What a funds transfer is *for*, declared to the card networks.
 *
 * Not a label. The business application id drives interchange, the network rules the transfer is
 * judged against, and in several markets whether it is permitted at all — a payroll disbursement
 * and a person-to-person transfer of the same amount to the same card are different products.
 * Sending the wrong one is a compliance problem, not a cosmetic one.
 *
 * The set divides in two. **Money transfer** moves funds between parties, and the networks expect
 * sender identification with it. **Funds disbursement** pays money out from a business to someone,
 * and generally does not. Which ids an account may actually use varies by gateway and
 * configuration; CyberSource falls back to the merchant's configured default when none is sent.
 */
enum BusinessApplicationId: string
{
    // Money transfer (MT)
    case AccountToAccount = 'AA';
    case BankInitiatedTransfer = 'BI';
    case CashDeposit = 'CD';
    case FundsTransfer = 'FT';
    case LiquidAssets = 'LA';
    case PersonToPerson = 'PP';
    case WalletTransfer = 'WT';

    // Funds disbursement (FD)
    case BusinessToBusiness = 'BB';
    case NonCardBillPay = 'BP';
    case CreditCardBillPay = 'CP';
    case GeneralDisbursement = 'FD';
    case GovernmentDisbursement = 'GD';
    case GamingPayout = 'GP';
    case LoyaltyPayment = 'LO';
    case MerchantSettlement = 'MD';
    case FasterRefund = 'MI';
    case OnlineGamblingPayout = 'OG';
    case PayrollAndPension = 'PD';
    case RequestToPay = 'RP';
    case PrepaidCardLoad = 'TU';

    /**
     * Human-readable description of what the id declares.
     */
    public function label(): string
    {
        return match ($this) {
            self::AccountToAccount => 'Account to account',
            self::BankInitiatedTransfer => 'Bank-initiated money transfer',
            self::CashDeposit => 'Cash deposit',
            self::FundsTransfer => 'Funds transfer',
            self::LiquidAssets => 'Liquid assets',
            self::PersonToPerson => 'Person-to-person money transfer',
            self::WalletTransfer => 'Wallet transfer (staged digital wallet)',
            self::BusinessToBusiness => 'Business-to-business supplier payment',
            self::NonCardBillPay => 'Non-card bill pay',
            self::CreditCardBillPay => 'Credit card bill pay',
            self::GeneralDisbursement => 'General funds disbursement',
            self::GovernmentDisbursement => 'Government disbursement or tax refund',
            self::GamingPayout => 'Gaming payout',
            self::LoyaltyPayment => 'Loyalty payment',
            self::MerchantSettlement => 'Merchant settlement',
            self::FasterRefund => 'Faster refund',
            self::OnlineGamblingPayout => 'Online gambling payout',
            self::PayrollAndPension => 'Payroll or pension disbursement',
            self::RequestToPay => 'Request-to-pay service',
            self::PrepaidCardLoad => 'Prepaid card load',
        };
    }

    /**
     * Whether this id declares a transfer of funds between parties, as opposed to a business
     * paying money out. Money transfers carry sender identification the networks require;
     * disbursements generally do not.
     */
    public function isMoneyTransfer(): bool
    {
        return match ($this) {
            self::AccountToAccount, self::BankInitiatedTransfer, self::CashDeposit,
            self::FundsTransfer, self::LiquidAssets, self::PersonToPerson, self::WalletTransfer => true,
            default => false,
        };
    }

    /**
     * Whether this id declares a business paying money out rather than moving it between parties.
     */
    public function isFundsDisbursement(): bool
    {
        return ! $this->isMoneyTransfer();
    }

    /**
     * Every id that declares a money transfer.
     *
     * @return list<self>
     */
    public static function moneyTransfers(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isMoneyTransfer()));
    }

    /**
     * Every id that declares a funds disbursement.
     *
     * @return list<self>
     */
    public static function fundsDisbursements(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isFundsDisbursement()));
    }
}
