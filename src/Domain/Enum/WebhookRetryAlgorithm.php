<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

use Hyprpay\Payments\Domain\ValueObject\WebhookRetryPolicy;

/**
 * How the delay between webhook delivery retries grows.
 *
 * With a first retry of 10 minutes and an interval of 30, {@see self::Arithmetic} gives
 * 10, 40, 70 minutes while {@see self::Geometric} gives 10, 300, 9000 — so geometric backs off
 * far more aggressively than it first appears. See {@see WebhookRetryPolicy}.
 */
enum WebhookRetryAlgorithm: string
{
    case Arithmetic = 'ARITHMETIC';
    case Geometric = 'GEOMETRIC';
}
