<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Http\Controller;

use Mgrunder\Fuzzer\Http\JsonResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommandController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return JsonResponseFactory::error(
                'Only GET requests are supported.',
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        $class = trim((string) $request->query->get('class', ''));
        $command = trim((string) $request->query->get('cmd', ''));
        $args = $request->query->get('args', []);

        if (!is_array($args)) {
            $args = [$args];
        }

        if ($class == '') {
            return JsonResponseFactory::error(
                'Missing "class" parameter.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($command === '') {
            return JsonResponseFactory::error(
                'Missing "cmd" parameter.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $client = $this->createClient($class);

        if ($client === null) {
            return JsonResponseFactory::error(
                sprintf('Unknown client: %s', var_export($class, true)),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $client->connect('localhost', 6379);
            $result = $client->$command(...$args);

            return JsonResponseFactory::payload([
                'status' => 'ok',
                'pid' => getmypid(),
                'class' => $class,
                'result' => $result,
            ]);
        } catch (\Throwable $exception) {
            return JsonResponseFactory::error(
                sprintf('Exception: %s', $exception->getMessage()),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function createClient(string $class): \Relay\Relay|\Redis|null
    {
        return match ($class) {
            'relay' => new \Relay\Relay(),
            'redis' => new \Redis(),
            default => null,
        };
    }
}
