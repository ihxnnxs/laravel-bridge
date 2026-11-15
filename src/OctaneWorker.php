<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Worker;

final class OctaneWorker extends Worker
{
    public function __construct(
        protected ApplicationFactory $appFactory,
        ?Client $client = null,
    ) {
        parent::__construct($appFactory, $client ?? new DummyClient());
    }

    /**
     * @param array<string, mixed> $initialInstances
     */
    #[\Override]
    public function boot(array $initialInstances = [], ?Application $application = null): void
    {
        if ($application === null) {
            parent::boot($initialInstances);
            return;
        }

        // Temporal activities already have a booted application.
        $this->app = $application;
        $this->bootRequest = $application->make(Request::class);

        $this->dispatchEvent($application, new WorkerStarting($application));
    }
}
