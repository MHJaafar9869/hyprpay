<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Console\ReconcileCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileCybersourceCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileFawryCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaylinkCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaymobCommand;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Illuminate\Container\Container;
use Symfony\Component\Console\Tester\CommandTester;

it('names and describes each reconcile command after its gateway', function (string $class, string $name, string $label): void {
    $command = new $class;

    expect($command)->toBeInstanceOf(ReconcileCommand::class)
        ->and($command->getName())->toBe($name)
        ->and($command->getDescription())->toContain($label);
})->with([
    'cybersource' => [ReconcileCybersourceCommand::class, 'gateway:reconcile:cybersource_uc', 'CyberSource UC'],
    'fawry' => [ReconcileFawryCommand::class, 'gateway:reconcile:fawry', 'Fawry'],
    'paymob' => [ReconcilePaymobCommand::class, 'gateway:reconcile:paymob', 'Paymob'],
    'paylink' => [ReconcilePaylinkCommand::class, 'gateway:reconcile:paylink', 'PayLink'],
]);

it('fetches each transaction and renders it in the table, exiting successfully', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['orderStatus' => 'PAID', 'fawryRefNumber' => 'F-77', 'merchantRefNumber' => 'ORD-77']);

    $tester = new CommandTester(reconcileCommand(ReconcileFawryCommand::class, $http));
    $tester->execute(['transaction' => ['ORD-77']]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('F-77')
        ->toContain('Captured')
        ->toContain('ORD-77');
});

it('exits with a failure code when a transaction cannot be reconciled', function (): void {
    $http = (new FakeHttpClient)->queueBody('{"reason":"boom"}', 500);

    $tester = new CommandTester(reconcileCommand(ReconcileFawryCommand::class, $http));
    $tester->execute(['transaction' => ['ORD-BAD']]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('lookup failed');
});

/**
 * Instantiate a reconcile command wired to a container whose factory drives the
 * given fake HTTP client, ready to run through Symfony's CommandTester.
 *
 * @param  class-string<ReconcileCommand>  $class
 */
function reconcileCommand(string $class, FakeHttpClient $http): ReconcileCommand
{
    // A container that also answers runningUnitTests(), which Illuminate's console
    // prompt configuration expects on the "laravel" instance during run().
    $container = new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };

    $container->instance(
        PaymentGatewayFactory::class,
        new PaymentGatewayFactory($http, recordingResolver(testCredentials())),
    );

    $command = new $class;
    $command->setLaravel($container);

    return $command;
}
