<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Http;

use Illuminate\Http\Request;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\Relay;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker as RoadRunnerWorker;
use Spiral\RoadRunnerLaravel\OctaneWorker;
use Spiral\RoadRunnerLaravel\WorkerInterface;
use Spiral\RoadRunnerLaravel\WorkerOptionsInterface;

final readonly class HttpWorker implements WorkerInterface
{
    #[\Override]
    public function start(WorkerOptionsInterface $options): void
    {
        // Laravel preserves generator callbacks under Octane. Set this before the
        // HTTP SDK caches server globals, so every request keeps the native path.
        $_SERVER['LARAVEL_OCTANE'] = true;

        $roadRunnerClient = new RoadRunnerClient(
            $psr7Client = new PSR7Worker(
                new RoadRunnerWorker(Relay::create($options->getRelayDsn())),
                new ServerRequestFactory(),
                new StreamFactory(),
                new UploadedFileFactory(),
            ),
        );

        $worker = new OctaneWorker(
            new ApplicationFactory($options->getAppBasePath()),
            $roadRunnerClient,
        );

        $worker->boot();

        try {
            while ($psr7Request = $psr7Client->waitRequest()) {
                /**
                 * @var Request $request
                 * @var RequestContext $context
                 */
                [$request, $context] = $roadRunnerClient->marshalRequest(new RequestContext([
                    'psr7Request' => $psr7Request,
                ]));

                $worker->handle($request, $context);

                unset($psr7Request, $request, $context);

                $worker->operationReleased();
            }
        } catch (\Throwable $e) {
            if (!$e instanceof RelayException) {
                report($e);
            }

            exit(1);
        } finally {
            $worker->terminate();
        }
    }
}
