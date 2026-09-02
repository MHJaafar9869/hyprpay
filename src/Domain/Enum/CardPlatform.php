<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Who the card was issued to — a person or an organisation.
 *
 * A commercial, corporate, or government card can qualify for Level 2/3 interchange when the
 * transaction carries the extra line-item and tax data those rates require, so knowing the
 * platform before authorizing is what makes supplying that data worthwhile.
 */
enum CardPlatform: string
{
    case Consumer = 'CONSUMER';
    case Business = 'BUSINESS';
    case Corporate = 'CORPORATE';
    case Commercial = 'COMMERCIAL';
    case Government = 'GOVERNMENT';

    /**
     * Whether the card was issued to an organisation rather than an individual.
     */
    public function isCommercial(): bool
    {
        return $this !== self::Consumer;
    }
}
