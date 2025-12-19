<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Http\Controller;

use Mgrunder\Fuzzer\Http\JsonResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class KeysController
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
            $relay = new \Relay\Relay('localhost', 6379);
            $keys = $relay->_getKeys();

            if (!is_array($keys)) {
                $keys = [];
            }

            return JsonResponseFactory::payload($keys);
        } catch (\Throwable $exception) {
            return JsonResponseFactory::error(
                $exception->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
