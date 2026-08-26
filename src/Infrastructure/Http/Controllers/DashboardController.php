<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http\Controllers;

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Infrastructure\Dashboard\DashboardData;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Serves the monitoring dashboard: the page, its activity feed, and the by-reference lookup.
 *
 * Stays thin by delegating every read model to {@see DashboardData}; it only resolves the
 * request-time inputs (the configured feed size, the chosen gateway) and returns the view
 * or JSON.
 */
final readonly class DashboardController
{
    /**
     * @param  DashboardData  $data  Presenter that builds the dashboard's read models.
     * @param  ViewFactory  $views  Renders the dashboard Blade view.
     * @param  ConfigRepository  $config  Provides the configured activity-feed size.
     */
    public function __construct(
        private DashboardData $data,
        private ViewFactory $views,
        private ConfigRepository $config,
    ) {}

    /**
     * Render the dashboard page: gateway health, headline stats, and the recent-activity feed.
     */
    public function index(): View
    {
        return $this->views->make('hyprpay::dashboard.index', $this->data->overview($this->limit()));
    }

    /**
     * Return the recent-activity feed as JSON for the page's live polling.
     */
    public function activity(): JsonResponse
    {
        return new JsonResponse($this->data->recentActivity($this->limit()));
    }

    /**
     * Return a payment's full recorded lifecycle — its event timeline and summary — as JSON.
     */
    public function lifecycle(Request $request): JsonResponse
    {
        $reference = trim(Value::string($request->input('reference')));

        if ($reference === '') {
            throw new HttpException(422, 'A payment reference is required.');
        }

        return new JsonResponse($this->data->lifecycle($reference));
    }

    /**
     * Look up a payment's history at a gateway by merchant reference and return it as JSON.
     */
    public function lookup(Request $request, PaymentGatewayFactory $factory): JsonResponse
    {
        $gateway = GatewayName::tryFrom(Value::string($request->input('gateway')));

        if (! $gateway instanceof GatewayName) {
            throw new HttpException(422, 'Unknown gateway.');
        }

        $reference = trim(Value::string($request->input('reference')));

        if ($reference === '') {
            throw new HttpException(422, 'A payment reference is required.');
        }

        return new JsonResponse($this->data->lookup($factory->make($gateway), $reference));
    }

    /**
     * The configured maximum number of activity records to read.
     */
    private function limit(): int
    {
        return max(1, Value::int($this->config->get('gateway.dashboard.store.limit'), 500));
    }
}
