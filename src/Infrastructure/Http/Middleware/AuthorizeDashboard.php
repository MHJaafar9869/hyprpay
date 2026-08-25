<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate that protects the monitoring dashboard, prepended to its middleware stack.
 *
 * Every request must satisfy the `viewHyprpay` authorization gate (defined by the service
 * provider, overridable by the host) — mirroring how Telescope and Horizon guard their
 * dashboards — otherwise it is rejected with a 403 before any dashboard code runs.
 */
final class AuthorizeDashboard
{
    /**
     * Reject the request unless the current user passes the `viewHyprpay` gate.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::check('viewHyprpay')) {
            throw new AccessDeniedHttpException('You are not authorized to view the Hyprpay dashboard.');
        }

        return $next($request);
    }
}
