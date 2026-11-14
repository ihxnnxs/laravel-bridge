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
 * Keeps file bodies bounded and lets resource-owning responses stream without PSR-7 buffering.
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
        $body = $response instanceof StreamableResponse ? $response->stream() : null;
        if ($body === null && $response instanceof StreamedResponse) {
            $callback = self::resolveStreamResponseCallback($response);
            $body = $callback === null ? null : $callback();
        }
        if ($body === null) {
            parent::respond($context, $octaneResponse);
            $context->data[self::RESPONSE_CLOSED] = true;
            return;
        }
        if (!\is_string($body) && !$body instanceof \Generator) {
            throw new \UnexpectedValueException('A streaming response must return a string or Generator.');
        }

        $stream = new ResponseStream();
        if ($body instanceof \Generator) {
            $body = $stream->wrap($body);
        }
        $headers = \array_map(
            static fn(array $values): array => \array_filter($values, \is_string(...)),
            $response->headers->all(),
        );
        $this->client->getHttpWorker()->respond($response->getStatusCode(), $body, $headers);
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
