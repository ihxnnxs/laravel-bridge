<?php

declare(strict_types=1);

use Spiral\RoadRunnerLaravel\Http\HttpWorker;
use Spiral\RoadRunnerLaravel\WorkerOptions;

require \dirname(__DIR__, 4) . '/vendor/autoload.php';

unset($_SERVER['LARAVEL_OCTANE']);
\define('BRIDGE_FIXTURE_EXPECTED_FAILURES', (int) ($argv[1] ?? 0));

(new HttpWorker())->start(new WorkerOptions(__DIR__ . '/app'));
