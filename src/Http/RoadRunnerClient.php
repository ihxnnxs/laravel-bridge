<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Http;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Spiral\RoadRunner\Http\PSR7Worker;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Keeps PSR-7 chunks bounded and preserves failures suppressed by the HTTP SDK.
 */
final class RoadRunnerClient extends \Laravel\Octane\RoadRunner\RoadRunnerClient
{
    private const RESPONSE_CLOSED = 'bridgeHttpResponseClosed';

    public function __construct(PSR7Worker $client)
    {
        $client->chunkSize = 65536;
        parent::__construct($client);
    }

    #[\Override]
    public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
    {
        $response = $octaneResponse->response;
        $callback = $response instanceof StreamedResponse ? self::resolveStreamResponseCallback($response) : null;
        if ($callback === null) {
            parent::respond($context, $octaneResponse);
            $context->data[self::RESPONSE_CLOSED] = true;
            return;
        }

        $stream = new ResponseStream();
        // Keep a generator callback so native Octane detects it and header conversion
        // cannot advance the body through StreamedResponse::sendContent().
        $response->setCallback(static function () use ($callback, $stream): \Generator {
            $body = $callback();
            if ($body instanceof \Generator) {
                return yield from $stream->wrap($body);
            }

            return $body;
        });

        try {
            parent::respond($context, $octaneResponse);
        } finally {
            $response->setCallback($callback);
        }

        $context->data[self::RESPONSE_CLOSED] = true;
        $stream->rethrowFailure();
    }

    #[\Override]
    public function error(\Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        // Octane still reports the failure, but the SDK has already sent the terminal frame.
        if (($context->data[self::RESPONSE_CLOSED] ?? false) === true) {
            return;
        }

        parent::error($e, $app, $request, $context);
    }
}
