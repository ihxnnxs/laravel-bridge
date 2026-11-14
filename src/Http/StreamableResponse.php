<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Http;

/**
 * A response whose generator owns the body and its resource-cleanup lifetime.
 */
interface StreamableResponse
{
    /**
     * @return \Generator<int, string>
     */
    public function stream(): \Generator;
}
