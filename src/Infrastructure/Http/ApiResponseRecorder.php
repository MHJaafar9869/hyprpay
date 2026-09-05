<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Hyprpay\Payments\Domain\Http\ApiResponse;

/**
 * Request-scoped buffer holding the gateway API responses captured during the current operation.
 *
 * RecordingHttpClient appends to it as each gateway call completes; the recording event
 * listener drains it when it writes the operation's activity record, so a response is
 * attached to the attempt that produced it. Draining clears the buffer, which makes the
 * dispatch of a payment event the operation boundary.
 *
 * Note the one gap that follows from that: a gateway call that dispatches no event — a
 * read-only lookup, say — leaves its responses buffered until the next event drains them.
 * The buffer is capped so this can never grow without bound, and callers that need a clean
 * slate can call {@see forget()}.
 */
final class ApiResponseRecorder
{
    /** @var list<ApiResponse> */
    private array $apiResponses = [];

    /**
     * @param  int  $limit  Most responses kept before the oldest are dropped.
     */
    public function __construct(private readonly int $limit = 20) {}

    /**
     * Append one captured response, dropping the oldest once the cap is reached.
     */
    public function record(ApiResponse $exchange): void
    {
        $this->apiResponses[] = $exchange;

        if (count($this->apiResponses) > $this->limit) {
            array_shift($this->apiResponses);
        }
    }

    /**
     * Return everything captured since the last drain and empty the buffer.
     *
     * @return list<ApiResponse>
     */
    public function take(): array
    {
        $taken = $this->apiResponses;
        $this->apiResponses = [];

        return $taken;
    }

    /**
     * Discard anything buffered without returning it.
     */
    public function forget(): void
    {
        $this->apiResponses = [];
    }
}
