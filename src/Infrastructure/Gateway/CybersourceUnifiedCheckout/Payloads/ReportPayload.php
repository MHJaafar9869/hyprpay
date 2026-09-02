<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CreateReportRequest;
use Hyprpay\Payments\Domain\Command\CreateReportSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\DownloadReportRequest;
use Hyprpay\Payments\Domain\Command\ListReportsRequest;
use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportSubscriptionType;

/**
 * Builds the CyberSource Reporting (`/reporting/v3/*`) request bodies and query strings.
 *
 * The reporting service splits its inputs across both: creating an ad-hoc report or a
 * subscription takes a JSON body, while searching and downloading take query parameters that
 * form part of the signed request target. Every builder carries the organization id, which
 * the gateway resolves from the credentials when the caller does not name one.
 */
final class ReportPayload
{
    /**
     * Build the POST /reporting/v3/reports body for a one-off report.
     *
     * @param  CreateReportRequest  $request  The report to generate.
     * @param  string  $organizationId  Organization the report is filed under.
     * @return array<string, mixed>
     */
    public static function adhoc(CreateReportRequest $request, string $organizationId): array
    {
        return array_filter([
            'organizationId' => $organizationId,
            'reportName' => $request->name,
            'reportDefinitionName' => ReportDefinitionName::toValue($request->definitionName),
            'reportMimeType' => $request->format->value,
            'reportStartTime' => self::timestamp($request->startTime),
            'reportEndTime' => self::timestamp($request->endTime),
            'timezone' => $request->timezone,
            'reportFields' => $request->fields,
            'reportFilters' => $request->filters,
            'groupName' => $request->groupName,
        ], filled(...));
    }

    /**
     * Build the PUT /reporting/v3/report-subscriptions body for a scheduled report.
     *
     * `startDay` is emitted only for the cadences that use it and `reportInterval` only for a
     * user-defined one, so a daily subscription does not carry fields CyberSource would reject.
     *
     * @param  CreateReportSubscriptionRequest  $request  The schedule to create or replace.
     * @param  string  $organizationId  Organization the subscription is filed under.
     * @return array<string, mixed>
     */
    public static function subscription(CreateReportSubscriptionRequest $request, string $organizationId): array
    {
        return array_filter([
            'organizationId' => $organizationId,
            'reportName' => $request->name,
            'reportDefinitionName' => ReportDefinitionName::toValue($request->definitionName),
            'reportMimeType' => $request->format->value,
            'reportFrequency' => $request->frequency->value,
            'reportFields' => $request->fields,
            'timezone' => $request->timezone,
            'startTime' => $request->startTime,
            'startDay' => $request->frequency->needsStartDay() ? $request->startDay : null,
            'reportInterval' => $request->frequency->needsInterval() ? $request->interval : null,
            'reportFilters' => $request->filters,
            'groupName' => $request->groupName,
        ], filled(...));
    }

    /**
     * Build the query string for GET /reporting/v3/reports, including the leading `?`.
     *
     * @param  ListReportsRequest  $request  Window and filters for the search.
     * @param  string  $organizationId  Organization to search.
     */
    public static function searchQuery(ListReportsRequest $request, string $organizationId): string
    {
        return self::query([
            'startTime' => self::timestamp($request->startTime),
            'endTime' => self::timestamp($request->endTime),
            'timeQueryType' => $request->timeQueryType,
            'organizationId' => $organizationId,
            'reportMimeType' => $request->format?->value,
            'reportFrequency' => $request->frequency?->value,
            'reportName' => $request->name,
            'reportDefinitionId' => $request->definitionId === null ? null : (string) $request->definitionId,
            'reportStatus' => $request->status?->value,
        ]);
    }

    /**
     * Build the query string for GET /reporting/v3/report-downloads, including the leading `?`.
     *
     * @param  DownloadReportRequest  $request  The report file to fetch.
     * @param  string  $organizationId  Organization the report belongs to.
     */
    public static function downloadQuery(DownloadReportRequest $request, string $organizationId): string
    {
        return self::query([
            'reportDate' => $request->reportDate,
            'reportName' => $request->name,
            'organizationId' => $organizationId,
        ]);
    }

    /**
     * Build the query string for the report-definition endpoints, including the leading `?`.
     *
     * The subscription type decides which family of definitions a name resolves against, and the
     * MIME type which format's field list is returned; both are omitted when not specified so
     * CyberSource applies its own defaults (CUSTOM and CSV).
     *
     * @param  ReportSubscriptionType|null  $subscriptionType  Family to resolve the definition under.
     * @param  ReportFormat|null  $format  Format whose field list is wanted.
     * @param  string  $organizationId  Organization the definitions belong to.
     */
    public static function definitionQuery(
        ?ReportSubscriptionType $subscriptionType,
        ?ReportFormat $format,
        string $organizationId,
    ): string {
        return self::query([
            'subscriptionType' => $subscriptionType?->value,
            'reportMimeType' => $format?->value,
            'organizationId' => $organizationId,
        ]);
    }

    /**
     * Build the query string for the subscription endpoints, which filter only by organization.
     *
     * @param  string  $organizationId  Organization whose subscriptions are addressed.
     */
    public static function organizationQuery(string $organizationId): string
    {
        return self::query(['organizationId' => $organizationId]);
    }

    /**
     * URL-encode the supplied parameters, dropping any that are unset, and prefix the result
     * with `?`. Returns an empty string when nothing survives, so a caller can concatenate it
     * onto a path unconditionally.
     *
     * @param  array<string, string|null>  $parameters
     */
    private static function query(array $parameters): string
    {
        $filtered = array_filter($parameters, filled(...));

        return $filtered === [] ? '' : '?'.http_build_query($filtered);
    }

    /**
     * Normalise a reporting timestamp to the millisecond-precision UTC form CyberSource's
     * reporting service expects (`YYYY-MM-DDTHH:mm:ss.SSSZ`).
     *
     * A bare `YYYY-MM-DD` is expanded to the start of that day, so the common calendar-date
     * form is accepted rather than rejected; anything already carrying a time is left as sent.
     *
     * @param  string  $timestamp  Caller-supplied window bound.
     */
    private static function timestamp(string $timestamp): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $timestamp) === 1
            ? $timestamp.'T00:00:00.000Z'
            : $timestamp;
    }
}
