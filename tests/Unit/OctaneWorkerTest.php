<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\WorkerStarting;
use Spiral\RoadRunnerLaravel\OctaneWorker;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OctaneWorkerTest
{
    public function test_fresh_boot_uses_native_initial_instances_and_boot_request_before_worker_starting(): void
    {
        $original = Container::getInstance();
        $facadeApplication = Facade::getFacadeApplication();
        try {
            $app = new Application();
            $app->instance('request', $request = Request::create('/boot'));
            $client = \Mockery::mock(Client::class);
            $factory = \Mockery::mock(ApplicationFactory::class);
            $factory->shouldReceive('createApplication')->once()
                ->with(['fixture' => 'value', Client::class => $client])->andReturn($app);
            $worker = new OctaneWorker($factory, $client);
            $started = 0;
            $app->make('events')->listen(WorkerStarting::class, static function (WorkerStarting $event) use ($worker, $app, $request, &$started): void {
                Assert::same($event->app, $app);
                Assert::same((new \ReflectionProperty($worker, 'bootRequest'))->getValue($worker), $request);
                ++$started;
            });

            $worker->boot(['fixture' => 'value']);

            Assert::same($worker->application(), $app);
            Assert::same($started, 1);
        } finally {
            Container::setInstance($original);
            Facade::setFacadeApplication($facadeApplication);
        }
    }

    public function test_prebooted_application_is_preserved_for_temporal_without_http_marker(): void
    {
        $original = Container::getInstance();
        $facadeApplication = Facade::getFacadeApplication();
        $server = $_SERVER;
        unset($_SERVER['LARAVEL_OCTANE']);
        try {
            $app = new Application();
            $app->instance('request', $request = Request::create('/boot'));
            $factory = \Mockery::mock(ApplicationFactory::class);
            $factory->shouldNotReceive('createApplication');
            $worker = new OctaneWorker($factory);
            $started = 0;
            $app->make('events')->listen(WorkerStarting::class, static function () use (&$started): void {
                ++$started;
            });

            $worker->boot(application: $app);

            Assert::same($worker->application(), $app);
            Assert::same((new \ReflectionProperty($worker, 'bootRequest'))->getValue($worker), $request);
            Assert::same($started, 1);
            Assert::false(isset($_SERVER['LARAVEL_OCTANE']));
        } finally {
            Container::setInstance($original);
            Facade::setFacadeApplication($facadeApplication);
            $_SERVER = $server;
        }
    }
}
