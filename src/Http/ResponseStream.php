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
        } catch (StreamStoppedException $exception) {
            // Native Octane owns cancellation and the SDK's stop acknowledgement.
            throw $exception;
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
