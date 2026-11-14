<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Http;

use Spiral\RoadRunner\Http\Exception\StreamStoppedException;

/**
 * @internal Preserves failures suppressed by the HTTP SDK after a partial response.
 */
final class ResponseStream
{
    private ?\Throwable $failure = null;

    public function wrap(\Generator $body): \Generator
    {
        try {
            return yield from $body;
        } catch (StreamStoppedException) {
            // Let the HTTP SDK acknowledge cancellation after the body's finally blocks run.
        } catch (\Throwable $exception) {
            $this->failure = $exception;
            throw $exception;
        }
    }

    public function rethrowFailure(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
