<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Http\Controller;

use Mgrunder\Fuzzer\Http\JsonResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClientsController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return JsonResponseFactory::error(
                'Only GET requests are supported.',
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        try {
            $redis = new \Redis(['host' => 'localhost', 'port' => 6379]);
            $clients = array_filter(
                $redis->client('list'),
                static fn (array $client): bool => ($client['lib-name'] ?? null) === 'relay'
            );

            return JsonResponseFactory::payload(array_values($clients));
        } catch (\Throwable $exception) {
            return JsonResponseFactory::error(
                $exception->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
