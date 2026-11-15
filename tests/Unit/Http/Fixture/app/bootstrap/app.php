<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\View\Engines\EngineResolver;
use Laravel\Octane\Events\OperationReleased;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Octane;

$app = new Application(\dirname(__DIR__));
$app->instance('config', new Repository(['app' => ['debug' => false]]));
$handler = \Mockery::mock(ExceptionHandler::class);
$handler->shouldReceive('report')->andReturnUsing(static function (\Throwable $exception): void {
    \fwrite(\STDERR, (string) $exception);
});
$app->instance(ExceptionHandler::class, $handler);
$app->instance('request', $bootRequest = Request::create('/boot'));
$app->instance('view.engine.resolver', new EngineResolver());
$app->singleton('octane', Octane::class);
$app->instance(ResponseFactoryContract::class, new ResponseFactory(\Mockery::mock(ViewFactory::class), \Mockery::mock(Redirector::class)));
$app->instance('fixture.state', $state = (object) ['released' => 0, 'request' => null, 'failures' => 0]);
$app->make('events')->listen(OperationReleased::class, static function () use ($app, $bootRequest, $state): void {
    if ($state->request?->get() !== null || $app->make('request') !== $bootRequest) {
        throw new \LogicException('OperationReleased retained the handled request.');
    }
    ++$state->released;
});
$app->make('events')->listen(WorkerErrorOccurred::class, static function (WorkerErrorOccurred $event) use ($state): void {
    if ($event->exception instanceof \DomainException && $event->exception->getMessage() === 'fixture stream failure') {
        ++$state->failures;
        return;
    }
    \fwrite(\STDERR, (string) $event->exception);
});
$app->make('events')->listen(WorkerStopping::class, static function () use ($state): void {
    if ($state->released !== 2 || $state->request?->get() !== null || $state->failures !== \BRIDGE_FIXTURE_EXPECTED_FAILURES) {
        throw new \LogicException('Bridge did not release both requests or report the expected failures.');
    }
});
$app->instance(KernelContract::class, new class($app, $app->make('router')) extends Kernel {
    protected $bootstrappers = [];

    public function handle($request)
    {
        $state = $this->app->make('fixture.state');
        $state->request = \WeakReference::create($request);
        $this->app->instance('request', $request);
        $fail = $request->getPathInfo() === '/failure';
        $response = \response()->stream(static function () use ($fail): \Generator {
            if ($fail) {
                yield 'partial';
                throw new \DomainException('fixture stream failure');
            }
            yield 'first';
            yield 'second';
        }, headers: ['X-Released' => (string) $state->released]);

        if (!isset($_SERVER['LARAVEL_OCTANE']) || !(new \ReflectionFunction($response->getCallback()))->isGenerator()) {
            throw new \LogicException('Bridge HTTP lost the native Laravel generator callback.');
        }

        return $response;
    }

    public function terminate($request, $response): void {}
});

return $app;
