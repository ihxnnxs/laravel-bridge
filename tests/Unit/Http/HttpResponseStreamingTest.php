<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Tests\Unit\Http;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Message\Command\StreamStop;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\StreamWorkerInterface;
use Spiral\RoadRunnerLaravel\Http\RoadRunnerClient;
use Spiral\RoadRunnerLaravel\Http\StreamableResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpResponseStreamingTest
{
    public function test_file_bytes_are_sent_in_bounded_chunks(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rr-file-');
        \file_put_contents($path, \str_repeat('archive', 32768));
        $response = new BinaryFileResponse($path);
        $response->prepare(Request::create('/download'));
        $frames = [];

        try {
            self::client($frames)->respond(new RequestContext(), new OctaneResponse($response));

            Assert::same(\strlen(\implode('', \array_column($frames, 'body'))), 229376);
            Assert::true(\count($frames) > 2);
            Assert::true(\max(\array_map(static fn(array $frame): int => \strlen($frame['body']), $frames)) <= 65536);
        } finally {
            \unlink($path);
        }
    }

    public function test_generator_flushes_before_completion_and_preserves_cookies(): void
    {
        $frames = [];
        $response = new StreamedResponse(static function () use (&$frames): \Generator {
            yield 'first';
            Assert::same(\implode('', \array_column($frames, 'body')), 'first');
            yield 'second';
        });
        $response->headers->setCookie(Cookie::create('download', 'scoped'));

        self::client($frames)->respond(new RequestContext(), new OctaneResponse($response));

        Assert::same(\implode('', \array_column($frames, 'body')), 'firstsecond');
        Assert::true(\str_contains($frames[0]['headers']['set-cookie'][0], 'download=scoped'));
    }

    public function test_resource_owning_response_releases_on_stop_without_advancing_body(): void
    {
        $frames = [];
        $response = new class extends Response implements StreamableResponse {
            public bool $finished = false;

            public function stream(): \Generator
            {
                try {
                    yield 'not-delivered';
                    Assert::fail('Stopped stream continued executing.');
                } finally {
                    $this->finished = true;
                }
            }
        };

        self::client($frames, stop: true)->respond(new RequestContext(), new OctaneResponse($response));

        Assert::true($response->finished);
        Assert::same(\implode('', \array_column($frames, 'body')), '');
        Assert::same($frames[\array_key_last($frames)]['header'], '');
    }

    public function test_partial_failure_propagates_without_an_extra_error_frame_and_next_request_succeeds(): void
    {
        $frames = [];
        $errors = [];
        $client = self::client($frames, errors: $errors);
        $context = new RequestContext();
        $failure = new \DomainException('stream failed');
        $response = new StreamedResponse(static function () use ($failure): \Generator {
            yield 'partial';
            throw $failure;
        });

        try {
            $client->respond($context, new OctaneResponse($response));
            Assert::fail('The callback failure was swallowed.');
        } catch (\DomainException $exception) {
            Assert::same($exception, $failure);
            $client->error($exception, self::application(), IlluminateRequest::create('/download'), $context);
        }

        Assert::same(\implode('', \array_column($frames, 'body')), 'partial');
        Assert::same(\count($frames), 2);
        Assert::same($frames[1]['eos'], true);
        Assert::same($errors, []);

        $client->respond(new RequestContext(), new OctaneResponse(new Response('healthy')));

        Assert::same(\count($frames), 3);
        Assert::same($frames[2]['status'], 200);
        Assert::same($frames[2]['body'], 'healthy');
        Assert::same($frames[2]['eos'], true);

        $client->error($failure, self::application(), IlluminateRequest::create('/another'), new RequestContext());
        Assert::same(\count($errors), 1);
    }

    public function test_generator_return_value_is_preserved(): void
    {
        $frames = [];
        $response = new StreamedResponse(static function (): \Generator {
            yield 'first';
            return 'last';
        });

        self::client($frames)->respond(new RequestContext(), new OctaneResponse($response));

        Assert::same(\implode('', \array_column($frames, 'body')), 'firstlast');
        Assert::same($frames[\array_key_last($frames)]['eos'], true);
    }

    public function test_octane_reports_a_partial_failure_through_worker_error_occurred(): void
    {
        $frames = [];
        $errors = [];
        $client = self::client($frames, errors: $errors);
        $context = new RequestContext();
        $failure = new \DomainException('stream failed');
        $response = new StreamedResponse(static function () use ($failure): \Generator {
            yield 'partial';
            throw $failure;
        });
        $app = self::application();
        $app->instance('env', 'production');
        $handler = \Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($failure);
        $app->instance(ExceptionHandler::class, $handler);
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen(WorkerErrorOccurred::class, [new ReportException(), 'handle']);
        $app->instance(DispatcherContract::class, $dispatcher);
        $worker = new class(new ApplicationFactory(__DIR__), $client) extends Worker {
            public function reportFailure(\Throwable $failure, Application $app, RequestContext $context): void
            {
                $this->handleWorkerError($failure, $app, IlluminateRequest::create('/download'), $context, false);
            }
        };

        try {
            $client->respond($context, new OctaneResponse($response));
            Assert::fail('The callback failure was swallowed.');
        } catch (\DomainException $exception) {
            Assert::same($exception, $failure);
            $worker->reportFailure($exception, $app, $context);
        }

        Assert::same($errors, []);
        Assert::same(\count($frames), 2);
    }

    public function test_failure_before_first_chunk_propagates_without_sending_a_response(): void
    {
        $frames = [];
        $errors = [];
        $client = self::client($frames, errors: $errors);
        $context = new RequestContext();
        $failure = new \DomainException('cannot open body');
        $response = new StreamedResponse(static function () use ($failure): \Generator {
            throw $failure;
            yield '';
        });

        try {
            $client->respond($context, new OctaneResponse($response));
            Assert::fail('The initial stream failure was swallowed.');
        } catch (\DomainException $exception) {
            Assert::same($exception, $failure);
            $client->error($exception, self::application(), IlluminateRequest::create('/download'), $context);
        }

        Assert::same($frames, []);
        Assert::same(\count($errors), 1);
    }

    public function test_transport_failure_does_not_mark_the_response_closed(): void
    {
        $worker = \Mockery::mock(StreamWorkerInterface::class);
        $worker->shouldReceive('withStreamMode')->once()->andReturnSelf();
        $worker->shouldReceive('getPayload')->with(StreamStop::class)->andReturn(null);
        $failure = new \RuntimeException('terminal frame failed');
        $worker->shouldReceive('respond')->once()->andThrow($failure);
        $worker->shouldReceive('error')->once()->with('Internal server error.');
        $client = new RoadRunnerClient(new PSR7Worker($worker, new ServerRequestFactory(), new StreamFactory(), new UploadedFileFactory()));
        $context = new RequestContext();
        $response = new StreamedResponse(static function (): \Generator {
            return 'body';
            yield '';
        });

        try {
            $client->respond($context, new OctaneResponse($response));
            Assert::fail('The transport failure was swallowed.');
        } catch (\RuntimeException $exception) {
            Assert::same($exception, $failure);
            $client->error($exception, self::application(), IlluminateRequest::create('/download'), $context);
        }
    }

    private static function application(): Application
    {
        $app = new Application();
        $app->instance('config', new Repository(['app' => ['debug' => false]]));

        return $app;
    }

    private static function client(array &$frames, bool $stop = false, array &$errors = []): RoadRunnerClient
    {
        $worker = \Mockery::mock(StreamWorkerInterface::class);
        $worker->shouldReceive('withStreamMode')->andReturnSelf();
        $worker->shouldReceive('getPayload')->with(StreamStop::class)->andReturn($stop ? new StreamStop('') : null);
        $worker->shouldReceive('error')->andReturnUsing(static function (string $error) use (&$errors): void {
            $errors[] = $error;
        });
        $worker->shouldReceive('respond')->andReturnUsing(static function (Payload $payload) use (&$frames): void {
            $header = $payload->header === '' ? [] : \json_decode($payload->header, true, flags: \JSON_THROW_ON_ERROR);
            $frames[] = ['body' => $payload->body, 'header' => $payload->header, 'eos' => $payload->eos, ...$header];
        });

        return new RoadRunnerClient(new PSR7Worker($worker, new ServerRequestFactory(), new StreamFactory(), new UploadedFileFactory()));
    }
}
