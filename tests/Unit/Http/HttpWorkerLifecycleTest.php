<?php

declare(strict_types=1);

namespace Spiral\RoadRunnerLaravel\Tests\Unit\Http;

use Spiral\Goridge\Frame;
use Spiral\Goridge\StreamRelay;
use Spiral\RoadRunner\PayloadFactory;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpWorkerLifecycleTest
{
    public function test_http_boot_preserves_native_generators_and_releases_each_request(): void
    {
        self::runScenario('/stream', 'firstsecond', 0);
    }

    public function test_partial_failure_is_reported_once_and_the_next_request_is_healthy(): void
    {
        self::runScenario('/failure', 'partial', 1);
    }

    private static function runScenario(string $firstPath, string $firstBody, int $failures): void
    {
        $inputFrames = '';
        foreach ([$firstPath, '/stream'] as $path) {
            $header = \json_encode([
                'remoteAddr' => '127.0.0.1',
                'protocol' => 'HTTP/1.1',
                'method' => 'GET',
                'uri' => 'http://localhost' . $path,
                'headers' => [],
                'cookies' => [],
                'uploads' => [],
                'attributes' => [],
                'rawQuery' => '',
                'parsed' => false,
            ], \JSON_THROW_ON_ERROR);
            $inputFrames .= Frame::packFrame(new Frame($header, [\strlen($header)], Frame::CODEC_JSON));
        }
        $process = new Process([\PHP_BINARY, __DIR__ . '/Fixture/http-worker.php', (string) $failures]);
        $process->setInput($inputFrames . Frame::packFrame(new Frame('', [0])));
        $process->setTimeout(10);
        $process->run();

        Assert::same($process->getExitCode(), 0, $process->getErrorOutput());
        Assert::same($process->getErrorOutput(), '');

        $input = \fopen('php://temp', 'w+b');
        $output = \fopen('php://temp', 'w+b');
        \fwrite($input, $process->getOutput());
        \rewind($input);
        $relay = new StreamRelay($input, $output);

        try {
            foreach ([$firstBody, 'firstsecond'] as $released => $expectedBody) {
                $body = '';
                do {
                    $frame = $relay->waitFrame();
                    Assert::false($frame->hasFlag(Frame::ERROR));
                    $payload = PayloadFactory::fromFrame($frame);
                    if ($payload->header === '') {
                        Assert::same($payload->body, '');
                        Assert::same($frame->byte10 & Frame::BYTE10_STREAM, 0);
                        break;
                    }
                    $headers = \json_decode($payload->header, true, flags: \JSON_THROW_ON_ERROR);
                    Assert::same($headers['status'], 200);
                    Assert::same($headers['headers']['x-released'], [(string) $released]);
                    $body .= $payload->body;
                } while (($frame->byte10 & Frame::BYTE10_STREAM) !== 0);
                Assert::same($body, $expectedBody);
            }
            Assert::same(\fread($input, 1), '');
        } finally {
            \fclose($input);
            \fclose($output);
        }
    }
}
