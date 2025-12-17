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
        $query = $request->query->all();
        $args = $query['args'] ?? [];

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
            $arguments = $this->normalizeArguments($client, $command, $args);
            $result = $client->$command(...$arguments);

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

    /**
     * Attempt to coerce string query arguments into the expected parameter
     * types so that methods requiring ints/bools do not throw type errors.
     *
     * @param array<int, mixed> $args
     * @return array<int, mixed>
     */
    private function normalizeArguments(\Relay\Relay|\Redis $client, string $command, array $args): array
    {
        try {
            $method = new \ReflectionMethod($client, $command);
        } catch (\ReflectionException) {
            return $args;
        }

        $parameters = $method->getParameters();
        foreach ($parameters as $index => $parameter) {
            if ($parameter->isVariadic()) {
                for ($i = $index; $i < count($args); $i++) {
                    $args[$i] = $this->coerceArgument($args[$i], $parameter);
                }
                break;
            }

            if (!array_key_exists($index, $args)) {
                continue;
            }

            $args[$index] = $this->coerceArgument($args[$index], $parameter);
        }

        return $args;
    }

    private function coerceArgument(mixed $value, \ReflectionParameter $parameter): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $this->castValue($value, $type) ?? $value;
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $named) {
                if (!$named instanceof \ReflectionNamedType) {
                    continue;
                }

                $cast = $this->castValue($value, $named);
                if ($cast !== null) {
                    return $cast;
                }
            }
        }

        return $value;
    }

    private function castValue(string $value, \ReflectionNamedType $type): mixed
    {
        if (!$type->isBuiltin()) {
            return null;
        }

        return match ($type->getName()) {
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default => null,
        };
    }
}
