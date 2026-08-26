<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Dashboard\Queries\RecentLogEntries;

/**
 * Create a fresh temporary log directory.
 */
function tempLogDir(): string
{
    $dir = sys_get_temp_dir().'/hyprpay-logs-'.bin2hex(random_bytes(5));
    mkdir($dir, 0777, true);

    return $dir;
}

it('parses recent log entries newest first, folding continuation lines', function (): void {
    $dir = tempLogDir();
    file_put_contents($dir.'/hyprpay-2026-08-26.log', implode("\n", [
        '[2026-08-26 13:01:19] local.INFO: Charged payment {"gateway":"cybersource_uc"}',
        '[2026-08-26 13:02:20] local.WARNING: Failed to record payment activity',
        '[2026-08-26 13:03:21] local.ERROR: Something broke',
        'Stack trace line 1',
        'Stack trace line 2',
        '',
    ]));

    $entries = (new RecentLogEntries($dir, 'hyprpay'))->recent(10);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['level'])->toBe('ERROR')
        ->and($entries[0]['message'])->toBe('Something broke')
        ->and($entries[0]['detail'])->toContain('Stack trace line 1')
        ->and($entries[0]['detail'])->toContain('Stack trace line 2')
        ->and($entries[2]['level'])->toBe('INFO')
        ->and($entries[2]['message'])->toBe('Charged payment')
        ->and($entries[2]['detail'])->toContain('cybersource_uc');
});

it('returns an empty list when no log file exists', function (): void {
    expect((new RecentLogEntries(tempLogDir(), 'hyprpay'))->recent(10))->toBe([]);
});

it('honours the read limit', function (): void {
    $dir = tempLogDir();
    $lines = [];
    for ($i = 0; $i < 5; $i++) {
        $lines[] = "[2026-08-26 13:0{$i}:00] local.INFO: line {$i}";
    }

    file_put_contents($dir.'/hyprpay-2026-08-26.log', implode("\n", $lines));

    expect((new RecentLogEntries($dir, 'hyprpay'))->recent(2))->toHaveCount(2);
});
