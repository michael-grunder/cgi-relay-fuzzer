<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Http\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class EndpointsController
{
    public function __invoke(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return new Response(
                'Only GET requests are supported.',
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            $endpoints = \Relay\Relay::_dumpEndpoints();
        } catch (\Throwable $exception) {
            return new Response(
                sprintf('Failed to dump endpoints: %s', $exception->getMessage()),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $format = strtolower((string) $request->query->get('format', 'html'));
        $accept = strtolower($request->headers->get('accept', ''));

        if ($format === 'json' || str_contains($accept, 'application/json')) {
            $jsonOptions = JsonResponse::DEFAULT_ENCODING_OPTIONS
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE;

            $response = new JsonResponse($endpoints, Response::HTTP_OK);
            $response->setEncodingOptions($jsonOptions);

            return $response;
        }

        $json = json_encode(
            $endpoints,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return new Response(
                'Failed to encode endpoints to JSON.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $body = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Relay Endpoints</title>
    <style>
        body { font-family: monospace; margin: 1rem; background-color: #111; color: #eee; }
        pre { white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
<pre>%s</pre>
</body>
</html>
HTML;

        $html = sprintf($body, htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
