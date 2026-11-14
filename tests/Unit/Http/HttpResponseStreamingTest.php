<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Tests\Unit\Http;

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
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

    public function test_generator_failure_is_not_swallowed(): void
    {
        $frames = [];
        $failure = new \DomainException('stream failed');
        $response = new StreamedResponse(static function () use ($failure): \Generator {
            yield 'partial';
            throw $failure;
        });

        try {
            self::client($frames)->respond(new RequestContext(), new OctaneResponse($response));
            Assert::fail('The callback failure was swallowed.');
        } catch (\DomainException $exception) {
            Assert::same($exception, $failure);
        }

        Assert::same(\implode('', \array_column($frames, 'body')), 'partial');
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

    public function test_failure_before_first_chunk_propagates_without_sending_a_response(): void
    {
        $frames = [];
        $failure = new \DomainException('cannot open body');
        $response = new StreamedResponse(static function () use ($failure): \Generator {
            throw $failure;
            yield '';
        });

        try {
            self::client($frames)->respond(new RequestContext(), new OctaneResponse($response));
            Assert::fail('The initial stream failure was swallowed.');
        } catch (\DomainException $exception) {
            Assert::same($exception, $failure);
        }

        Assert::same($frames, []);
    }

    private static function client(array &$frames, bool $stop = false): RoadRunnerClient
    {
        $worker = \Mockery::mock(StreamWorkerInterface::class);
        $worker->shouldReceive('withStreamMode')->once()->andReturnSelf();
        $worker->shouldReceive('getPayload')->with(StreamStop::class)->andReturn($stop ? new StreamStop('') : null);
        $worker->shouldReceive('respond')->andReturnUsing(static function (Payload $payload) use (&$frames): void {
            $header = $payload->header === '' ? [] : \json_decode($payload->header, true, flags: \JSON_THROW_ON_ERROR);
            $frames[] = ['body' => $payload->body, 'header' => $payload->header, 'eos' => $payload->eos, ...$header];
        });

        return new RoadRunnerClient(new PSR7Worker($worker, new ServerRequestFactory(), new StreamFactory(), new UploadedFileFactory()));
    }
}
