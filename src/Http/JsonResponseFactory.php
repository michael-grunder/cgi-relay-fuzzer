<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class JsonResponseFactory
{
    public static function payload(mixed $data, int $statusCode = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $statusCode, $headers);

        return self::withEncodingOptions($response);
    }

    public static function error(string $message, int $statusCode = Response::HTTP_BAD_REQUEST, array $context = []): JsonResponse
    {
        $payload = array_merge([
            'status' => 'error',
            'error' => $message,
        ], $context);

        $response = new JsonResponse($payload, $statusCode);

        return self::withEncodingOptions($response);
    }

    private static function withEncodingOptions(JsonResponse $response): JsonResponse
    {
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);

        return $response;
    }
}
