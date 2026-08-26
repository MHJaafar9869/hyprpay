<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Queries;

use Hyprpay\Payments\Domain\Contract\ReadsLog;

/**
 * Reads recent entries from the SDK's dedicated daily log files (the "hyprpay" channel).
 *
 * Tails the most recently written `{prefix}*.log` in the log directory, parses the standard
 * Laravel line format (`[time] env.LEVEL: message …context`), folds continuation lines (stack
 * traces, JSON context) into the entry they belong to, and returns entries newest first.
 * Degrades to an empty list when no log file is present or readable.
 */
final readonly class RecentLogEntries implements ReadsLog
{
    /**
     * @param  string  $directory  Directory the SDK's log files live in (e.g. storage/logs).
     * @param  string  $prefix  Filename prefix of the SDK's log channel (e.g. "hyprpay").
     * @param  int  $maxBytes  How many trailing bytes of the newest file to scan.
     */
    public function __construct(
        private string $directory,
        private string $prefix,
        private int $maxBytes = 262144,
    ) {}

    public function recent(int $limit): array
    {
        $file = $this->latestFile();

        if ($file === null) {
            return [];
        }

        return array_slice(array_reverse($this->parse($this->tail($file))), 0, max(0, $limit));
    }

    /**
     * The most recently modified log file for the channel, or null when none exist.
     */
    private function latestFile(): ?string
    {
        $files = glob($this->directory.'/'.$this->prefix.'*.log');

        if ($files === false || $files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return $files[0];
    }

    /**
     * Read the trailing bytes of a file (the whole file when it is smaller).
     */
    private function tail(string $file): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        $size = filesize($file);

        if ($size !== false && $size > $this->maxBytes) {
            fseek($handle, -$this->maxBytes, SEEK_END);
        }

        $data = stream_get_contents($handle);
        fclose($handle);

        return $data === false ? '' : $data;
    }

    /**
     * Parse raw log text into entries, folding continuation lines into the preceding entry.
     *
     * @return list<array{time: string, level: string, message: string, detail: string}>
     */
    private function parse(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            if (preg_match('/^\[(?<time>[^\]]+)\]\s+\S+\.(?<level>[A-Z]+):\s?(?<message>.*)$/', $line, $matches) === 1) {
                [$message, $context] = $this->splitContext($matches['message']);
                $entries[] = ['time' => $matches['time'], 'level' => $matches['level'], 'message' => $message, 'detail' => $context];

                continue;
            }

            if ($entries !== [] && trim($line) !== '') {
                $last = count($entries) - 1;
                $entries[$last]['detail'] = ltrim($entries[$last]['detail']."\n".$line, "\n");
            }
        }

        return $entries;
    }

    /**
     * Split a trailing JSON/array context blob off the end of a log message.
     *
     * @return array{0: string, 1: string} The bare message and its context (empty when there is none).
     */
    private function splitContext(string $message): array
    {
        if (preg_match('/^(?<msg>.*?)\s(?<ctx>[\{\[].*[\}\]])\s*$/', $message, $matches) === 1) {
            return [$matches['msg'], $matches['ctx']];
        }

        return [$message, ''];
    }
}
