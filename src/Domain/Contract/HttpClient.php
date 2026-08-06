<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;

/**
 * Outbound-HTTP port used by gateway drivers to reach remote gateway APIs.
 *
 * Decouples the SDK from any concrete HTTP transport so it can be backed by a
 * real client (LaravelHttpClient) in production or a stub (FakeHttpClient) in tests.
 */
interface HttpClient
{
    /**
     * Dispatch an HTTP request and return the resulting response.
     *
     * @param  HttpRequest  $request  The fully-formed request (method, URL, headers, body).
     * @return HttpResponse The response returned by the remote endpoint.
     */
    public function send(HttpRequest $request): HttpResponse;
}
