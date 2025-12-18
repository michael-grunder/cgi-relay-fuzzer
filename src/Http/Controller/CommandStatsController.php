<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Http\Controller;

use Mgrunder\Fuzzer\Http\JsonResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommandStatsController
{
    private const PATTERN = '/calls=(\d+),usec=(\d+),usec_per_call=([\d.]+),rejected_calls=(\d+),failed_calls=(\d+)/';

    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return JsonResponseFactory::error(
                'Only GET requests are supported.',
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        try {
            return JsonResponseFactory::payload($this->collectCommandStats());
        } catch (\Throwable $exception) {
            return JsonResponseFactory::error(
                $exception->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function collectCommandStats(): array
    {
        $redis = new \Redis(['host' => 'localhost', 'port' => 6379]);
        $stats = $redis->info('commandstats');

        $result = [];

        foreach ($stats as $command => $info) {
            if (!is_string($command) || !is_string($info)) {
                continue;
            }

            if (preg_match(self::PATTERN, $info, $matches) !== 1) {
                continue;
            }

            $name = str_replace(['cmd_', 'cmdstat_'], '', $command);
            $result[$name] = [
                'command' => $name,
                'calls' => (int) $matches[1],
                'usec' => (int) $matches[2],
                'usec_per_call' => (float) $matches[3],
                'rejected_calls' => (int) $matches[4],
                'failed_calls' => (int) $matches[5],
            ];
        }

        //usort(
        //    $result,
        //    static fn (array $left, array $right): int => strcmp($left['command'], $right['command'])
        //);

        return $result;
    }
}
