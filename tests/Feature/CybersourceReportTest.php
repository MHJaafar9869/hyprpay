<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CreateReportRequest;
use Hyprpay\Payments\Domain\Command\CreateReportSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\DownloadReportRequest;
use Hyprpay\Payments\Domain\Command\ListReportsRequest;
use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;
use Hyprpay\Payments\Domain\Enum\ReportStatus;
use Hyprpay\Payments\Domain\Enum\ReportSubscriptionType;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @param  array<string, mixed>  $overrides
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function reportGateway(array $overrides = []): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials($overrides), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function reportBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('queues an adhoc report, normalising the window to millisecond UTC', function (): void {
    [$gateway, $http] = reportGateway();

    $accepted = $gateway->createReport(new CreateReportRequest(
        name: 'settlement-sept',
        definitionName: ReportDefinitionName::TransactionRequest,
        startTime: '2026-09-01',
        endTime: '2026-09-30T23:59:59.000Z',
        fields: ['Request.RequestID', 'Request.TransactionDate'],
        timezone: 'GMT',
    ));

    $request = $http->lastRequest();
    $body = reportBody($http);

    expect($accepted)->toBeTrue()
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/reporting/v3/reports')
        ->and($request?->header('Signature'))->not->toBeNull()
        ->and($body['reportName'])->toBe('settlement-sept')
        ->and($body['reportDefinitionName'])->toBe('TransactionRequestClass')
        ->and($body['reportMimeType'])->toBe('text/csv')
        ->and($body['reportStartTime'])->toBe('2026-09-01T00:00:00.000Z')
        ->and($body['reportEndTime'])->toBe('2026-09-30T23:59:59.000Z')
        ->and($body['reportFields'])->toBe(['Request.RequestID', 'Request.TransactionDate'])
        ->and($body['timezone'])->toBe('GMT')
        ->and($body['organizationId'])->toBe('test_merchant');
});

it('scopes reporting calls to the organization_id credential when one is configured', function (): void {
    [$gateway, $http] = reportGateway(['extra' => ['organization_id' => 'org_9']]);

    $gateway->createReport(new CreateReportRequest(
        name: 'r', definitionName: 'D', startTime: '2026-09-01', endTime: '2026-09-02',
    ));

    expect(reportBody($http)['organizationId'])->toBe('org_9');
});

it('lets the request override the organization for a single call', function (): void {
    [$gateway, $http] = reportGateway(['extra' => ['organization_id' => 'org_9']]);

    $gateway->createReport(new CreateReportRequest(
        name: 'r', definitionName: 'D', startTime: '2026-09-01', endTime: '2026-09-02', organizationId: 'org_other',
    ));

    expect(reportBody($http)['organizationId'])->toBe('org_other');
});

it('lists reports over a window with the filters in the signed query', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson([
        'reportSearchResults' => [
            [
                'reportId' => 'rep_1',
                'reportName' => 'settlement-sept',
                'reportDefinitionId' => '210',
                'status' => 'COMPLETED',
                'reportFrequency' => 'DAILY',
                'reportMimeType' => 'text/csv',
                'reportStartTime' => '2026-09-01T00:00:00Z',
                'reportEndTime' => '2026-09-02T00:00:00Z',
                'reportCompletedTime' => '2026-09-02T01:15:00Z',
                'timezone' => 'GMT',
                'organizationId' => 'test_merchant',
            ],
            ['reportId' => 'rep_2', 'reportName' => 'other', 'status' => 'RUNNING'],
        ],
    ]);

    $reports = $gateway->listReports(new ListReportsRequest(
        startTime: '2026-09-01',
        endTime: '2026-09-30',
        status: ReportStatus::Completed,
        format: ReportFormat::Csv,
        name: 'settlement-sept',
    ));

    $request = $http->lastRequest();

    expect($reports)->toHaveCount(2)
        ->and($reports[0]->reportId)->toBe('rep_1')
        ->and($reports[0]->status)->toBe(ReportStatus::Completed)
        ->and($reports[0]->frequency)->toBe(ReportFrequency::Daily)
        ->and($reports[0]->format)->toBe(ReportFormat::Csv)
        ->and($reports[0]->isDownloadable())->toBeTrue()
        ->and($reports[1]->status)->toBe(ReportStatus::Running)
        ->and($reports[1]->status?->isInProgress())->toBeTrue()
        ->and($reports[1]->isDownloadable())->toBeFalse()
        ->and($request?->method)->toBe('GET')
        ->and($request?->url)->toBe(
            'https://apitest.cybersource.com/reporting/v3/reports'
            .'?startTime=2026-09-01T00%3A00%3A00.000Z&endTime=2026-09-30T00%3A00%3A00.000Z'
            .'&timeQueryType=executedTime&organizationId=test_merchant&reportMimeType=text%2Fcsv'
            .'&reportName=settlement-sept&reportStatus=COMPLETED'
        )
        ->and($request?->header('Signature'))->not->toBeNull();
});

it('returns an empty list when no reports matched', function (): void {
    [$gateway] = reportGateway();

    expect($gateway->listReports(new ListReportsRequest(startTime: '2026-09-01', endTime: '2026-09-30')))->toBe([]);
});

it('reads a single report record by id, accepting the reportStatus spelling', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson([
        'reportId' => 'rep_1',
        'reportName' => 'settlement-sept',
        'reportStatus' => 'NO_DATA',
        'reportMimeType' => 'application/xml',
    ]);

    $report = $gateway->getReport('rep_1');

    expect($report->status)->toBe(ReportStatus::NoData)
        ->and($report->status?->isReady())->toBeFalse()
        ->and($report->status?->isFailed())->toBeFalse()
        ->and($report->format)->toBe(ReportFormat::Xml)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/reporting/v3/reports/rep_1');
});

it('downloads a report file as a raw body with a matching Accept header', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueBody("requestId,amount\n123,10.00\n");

    $file = $gateway->downloadReport(new DownloadReportRequest(
        name: 'settlement-sept',
        reportDate: '2026-09-02',
    ));

    $request = $http->lastRequest();

    expect($file->content)->toBe("requestId,amount\n123,10.00\n")
        ->and($file->isEmpty())->toBeFalse()
        ->and($file->bytes())->toBe(27)
        ->and($file->filename())->toBe('settlement-sept-2026-09-02.csv')
        ->and($request?->method)->toBe('GET')
        ->and($request?->header('Accept'))->toBe('text/csv')
        ->and($request?->url)->toBe(
            'https://apitest.cybersource.com/reporting/v3/report-downloads'
            .'?reportDate=2026-09-02&reportName=settlement-sept&organizationId=test_merchant'
        );
});

it('asks for xml when the report was generated as xml', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueBody('<Report/>');

    $file = $gateway->downloadReport(new DownloadReportRequest(
        name: 'r', reportDate: '2026-09-02', format: ReportFormat::Xml,
    ));

    expect($http->lastRequest()?->header('Accept'))->toBe('application/xml')
        ->and($file->filename())->toBe('r-2026-09-02.xml');
});

it('derives the download request from a listed report, keyed on the period end date', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['reportSearchResults' => [[
        'reportId' => 'rep_1',
        'reportName' => 'settlement-sept',
        'status' => 'COMPLETED',
        'reportMimeType' => 'text/csv',
        'reportEndTime' => '2026-09-02T00:00:00Z',
        'organizationId' => 'org_9',
    ]]]);

    $download = $gateway->listReports(new ListReportsRequest(startTime: '2026-09-01', endTime: '2026-09-30'))[0]
        ->downloadRequest();

    expect($download?->name)->toBe('settlement-sept')
        ->and($download?->reportDate)->toBe('2026-09-02')
        ->and($download?->format)->toBe(ReportFormat::Csv)
        ->and($download?->organizationId)->toBe('org_9');
});

it('refuses to derive a download request for a report that is not finished', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['reportSearchResults' => [[
        'reportId' => 'rep_2', 'reportName' => 'x', 'status' => 'QUEUED', 'reportEndTime' => '2026-09-02T00:00:00Z',
    ]]]);

    $report = $gateway->listReports(new ListReportsRequest(startTime: '2026-09-01', endTime: '2026-09-30'))[0];

    expect($report->downloadRequest())->toBeNull()
        ->and($report->status?->isInProgress())->toBeTrue();
});

it('schedules a daily report subscription with a signed put', function (): void {
    [$gateway, $http] = reportGateway();

    $accepted = $gateway->createReportSubscription(new CreateReportSubscriptionRequest(
        name: 'nightly-settlement',
        definitionName: ReportDefinitionName::TransactionRequest,
        fields: ['Request.RequestID'],
        startTime: '0200',
        timezone: 'GMT',
    ));

    $request = $http->lastRequest();
    $body = reportBody($http);

    expect($accepted)->toBeTrue()
        ->and($request?->method)->toBe('PUT')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/reporting/v3/report-subscriptions')
        ->and($request?->header('Signature'))->toContain('headers="(request-target) host digest v-c-date v-c-merchant-id"')
        ->and($body['reportName'])->toBe('nightly-settlement')
        ->and($body['reportFrequency'])->toBe('DAILY')
        ->and($body['startTime'])->toBe('0200')
        ->and($body['reportFields'])->toBe(['Request.RequestID'])
        ->and($body)->not->toHaveKey('startDay')
        ->and($body)->not->toHaveKey('reportInterval');
});

it('sends startDay only for the cadences that use it', function (): void {
    [$gateway, $http] = reportGateway();

    $gateway->createReportSubscription(new CreateReportSubscriptionRequest(
        name: 'weekly', definitionName: 'D', fields: ['f'], startTime: '0200',
        frequency: ReportFrequency::Weekly, startDay: 2,
    ));

    expect(reportBody($http)['startDay'])->toBe(2);

    $gateway->createReportSubscription(new CreateReportSubscriptionRequest(
        name: 'daily', definitionName: 'D', fields: ['f'], startTime: '0200',
        frequency: ReportFrequency::Daily, startDay: 2,
    ));

    expect(reportBody($http))->not->toHaveKey('startDay');
});

it('sends an interval only for a user-defined cadence', function (): void {
    [$gateway, $http] = reportGateway();

    $gateway->createReportSubscription(new CreateReportSubscriptionRequest(
        name: 'every-2h', definitionName: 'D', fields: ['f'], startTime: '0200',
        frequency: ReportFrequency::UserDefined, interval: 'PT2H30M',
    ));

    expect(reportBody($http)['reportInterval'])->toBe('PT2H30M')
        ->and(ReportFrequency::UserDefined->needsInterval())->toBeTrue()
        ->and(ReportFrequency::Daily->needsInterval())->toBeFalse();
});

it('lists report subscriptions', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['subscriptions' => [[
        'reportName' => 'nightly-settlement',
        'reportDefinitionName' => 'TransactionRequestClass',
        'reportFrequency' => 'DAILY',
        'reportMimeType' => 'text/csv',
        'startTime' => '0200',
        'timezone' => 'GMT',
        'reportFields' => ['Request.RequestID', 'Request.TransactionDate'],
    ]]]);

    $subscriptions = $gateway->listReportSubscriptions();

    expect($subscriptions)->toHaveCount(1)
        ->and($subscriptions[0]->name)->toBe('nightly-settlement')
        ->and($subscriptions[0]->frequency)->toBe(ReportFrequency::Daily)
        ->and($subscriptions[0]->format)->toBe(ReportFormat::Csv)
        ->and($subscriptions[0]->fields)->toBe(['Request.RequestID', 'Request.TransactionDate'])
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/reporting/v3/report-subscriptions?organizationId=test_merchant');
});

it('reads and deletes a subscription by report name, url-encoding the name in the path', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['reportName' => 'nightly settlement', 'reportFrequency' => 'MONTHLY', 'startDay' => 1]);

    $subscription = $gateway->getReportSubscription('nightly settlement');

    expect($subscription->frequency)->toBe(ReportFrequency::Monthly)
        ->and($subscription->startDay)->toBe(1)
        ->and($http->lastRequest()?->url)->toBe(
            'https://apitest.cybersource.com/reporting/v3/report-subscriptions/nightly%20settlement?organizationId=test_merchant'
        );

    expect($gateway->deleteReportSubscription('nightly settlement'))->toBeTrue()
        ->and($http->lastRequest()?->method)->toBe('DELETE')
        ->and($http->lastRequest()?->header('Digest'))->toBeNull();
});

it('throws when the reporting api rejects the request', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['message' => 'Invalid report definition'], 400);

    expect(fn (): bool => $gateway->createReport(new CreateReportRequest(
        name: 'r', definitionName: 'nope', startTime: '2026-09-01', endTime: '2026-09-02',
    )))->toThrow(GatewayRequestException::class);
});

it('discovers the report definitions a merchant may run, rather than hardcoding them', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['reportDefinitions' => [
        ['reportDefinitionName' => 'TransactionRequestClass', 'reportDefinitionId' => 210, 'description' => 'Transaction requests'],
        ['reportDefinitionName' => 'ChargebackAndRetrievalDetailClass', 'reportDefinitionId' => 310],
    ]]);

    $definitions = $gateway->listReportDefinitions(ReportSubscriptionType::Custom);

    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->name)->toBe('TransactionRequestClass')
        ->and($definitions[0]->definitionName())->toBe(ReportDefinitionName::TransactionRequest)
        ->and($definitions[1]->definitionName())->toBe(ReportDefinitionName::ChargebackAndRetrievalDetail)
        ->and($definitions[0]->id)->toBe(210)
        ->and($definitions[0]->description)->toBe('Transaction requests')
        ->and($definitions[0]->fields)->toBe([])
        ->and($http->lastRequest()?->method)->toBe('GET')
        ->and($http->lastRequest()?->url)->toBe(
            'https://apitest.cybersource.com/reporting/v3/report-definitions'
            .'?subscriptionType=CUSTOM&organizationId=test_merchant'
        );
});

it('reads one definition with the fields it offers, tolerating cybersource own name typo', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson([
        'reportDefintionName' => 'TransactionRequestClass',
        'reportDefinitionId' => 210,
        'subscriptionType' => 'CUSTOM',
        'supportedFormats' => ['text/csv', 'application/xml'],
        'attributes' => [
            ['id' => '1', 'name' => 'Request.RequestID', 'required' => true, 'default' => true, 'description' => 'Request id'],
            ['id' => '2', 'name' => 'Request.TransactionDate', 'default' => true],
            ['id' => '3', 'name' => 'Request.MerchantID', 'filterType' => 'string', 'supportedType' => 'any'],
        ],
    ]);

    $definition = $gateway->getReportDefinition(ReportDefinitionName::TransactionRequest, format: ReportFormat::Csv);

    expect($definition->name)->toBe('TransactionRequestClass')
        ->and($definition->definitionName())->toBe(ReportDefinitionName::TransactionRequest)
        ->and($definition->subscriptionType)->toBe(ReportSubscriptionType::Custom)
        ->and($definition->supportedFormats)->toBe([ReportFormat::Csv, ReportFormat::Xml])
        ->and($definition->supports(ReportFormat::Xml))->toBeTrue()
        ->and($definition->fieldNames())->toBe([
            'Request.RequestID', 'Request.TransactionDate', 'Request.MerchantID',
        ])
        ->and($definition->requiredFieldNames())->toBe(['Request.RequestID'])
        ->and($definition->fields[0]->isRequired)->toBeTrue()
        ->and($definition->fields[2]->isRequired)->toBeFalse()
        ->and($definition->fields[2]->filterType)->toBe('string')
        ->and($definition->fields[2]->supportedValues)->toBe('any')
        ->and($http->lastRequest()?->url)->toBe(
            'https://apitest.cybersource.com/reporting/v3/report-definitions/TransactionRequestClass'
            .'?reportMimeType=text%2Fcsv&organizationId=test_merchant'
        );
});

it('drops a report format the sdk does not model rather than guessing', function (): void {
    [$gateway, $http] = reportGateway();
    $http->queueJson(['reportDefintionName' => 'X', 'supportedFormats' => ['text/csv', 'application/pdf']]);

    $definition = $gateway->getReportDefinition('X');

    expect($definition->supportedFormats)->toBe([ReportFormat::Csv])
        ->and($definition->supports(ReportFormat::Xml))->toBeFalse();
});

it('returns an empty catalogue when the merchant is entitled to no definitions', function (): void {
    [$gateway] = reportGateway();

    expect($gateway->listReportDefinitions())->toBe([]);
});

it('renders a documented report type from its enum case and a custom one from a raw string', function (): void {
    [$gateway, $http] = reportGateway();

    $gateway->createReport(new CreateReportRequest(
        name: 'r', definitionName: ReportDefinitionName::RecurringBillingDetail,
        startTime: '2026-09-01', endTime: '2026-09-02',
    ));

    expect(reportBody($http)['reportDefinitionName'])->toBe('RecurringBillingDetailClass');

    $gateway->createReport(new CreateReportRequest(
        name: 'r', definitionName: 'MyCustomPortfolioClass',
        startTime: '2026-09-01', endTime: '2026-09-02',
    ));

    expect(reportBody($http)['reportDefinitionName'])->toBe('MyCustomPortfolioClass');
});

it('carries every documented report definition name with its title', function (): void {
    expect(ReportDefinitionName::cases())->toHaveCount(19)
        ->and(ReportDefinitionName::JpTransactionDetail->value)->toBe('JPTransactionDetailClass')
        ->and(ReportDefinitionName::ExceptionDetail->label())->toBe('Transaction Exception Detail Report')
        ->and(ReportDefinitionName::DecisionManagerEventDetail->label())->toBe('Decision Manager Event Detail Report')
        ->and(ReportDefinitionName::allValues())->toContain('PaymentBatchDetailClass', 'AgingDetailClass');
});

it('resolves a name it does not model to null rather than treating it as an error', function (): void {
    expect(ReportDefinitionName::resolve('MyCustomPortfolioClass'))->toBeNull()
        ->and(ReportDefinitionName::resolve(null))->toBeNull()
        ->and(ReportDefinitionName::resolve('FeeDetailClass'))->toBe(ReportDefinitionName::FeeDetail)
        ->and(ReportDefinitionName::resolve(ReportDefinitionName::FeeDetail))->toBe(ReportDefinitionName::FeeDetail)
        ->and(ReportDefinitionName::toValue('RawName'))->toBe('RawName')
        ->and(ReportDefinitionName::toValue(ReportDefinitionName::FeeDetail))->toBe('FeeDetailClass');
});
