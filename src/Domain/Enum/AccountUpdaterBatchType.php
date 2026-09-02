<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Kind of Account Updater batch being submitted.
 *
 * The card networks split the service in two: Visa and Mastercard answer ad-hoc update
 * requests, while American Express requires cards to be *registered* first and then reports
 * changes against that registry. A batch carries one kind or the other, never both, so cards
 * are grouped by network before submission.
 */
enum AccountUpdaterBatchType: string
{
    case OneOff = 'oneOff';
    case AmexRegistration = 'amexRegistration';
}
