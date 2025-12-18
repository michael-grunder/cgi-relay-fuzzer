<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer\Console;

use Mgrunder\Fuzzer\CmdEndpoint;
use Mgrunder\Fuzzer\CommandStatsEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kill-fuzz', description: 'Targeted reproduction of stale cache scenarios by killing relay clients or workers.')]
final class KillFuzzCommand extends Command
{
    private const VALID_WRITERS = ['redis', 'relay'];
    private const KILL_MODE_CLIENT = 'client';
    private const KILL_MODE_WORKER = 'worker';
    /**
     * @var array<int, array{type: string, prefix: string, write: string, read: string}>
     */
    private const OPERATION_PROFILES = [
        ['type' => 'string', 'prefix' => 'STRING', 'write' => 'set', 'read' => 'get'],
        ['type' => 'set', 'prefix' => 'SET', 'write' => 'sadd', 'read' => 'smembers'],
        ['type' => 'list', 'prefix' => 'LIST', 'write' => 'rpush', 'read' => 'lrange'],
        ['type' => 'hash', 'prefix' => 'HASH', 'write' => 'hmset', 'read' => 'hgetall'],
    ];

    private int $valueSequence = 0;

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Relay host to target', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Relay port to target', 8080)
            ->addOption('redis-host', null, InputOption::VALUE_REQUIRED, 'Redis host for raw client operations', '127.0.0.1')
            ->addOption('redis-port', null, InputOption::VALUE_REQUIRED, 'Redis port for raw client operations', 6379)
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'RNG seed (default: random)')
            ->addOption('writers', null, InputOption::VALUE_REQUIRED, 'Comma separated list of writer clients (relay,redis)', 'relay,redis')
            ->addOption('key-prefix', null, InputOption::VALUE_REQUIRED, 'Prefix for deterministic keys', 'fuzz:key')
            ->addOption('keys', null, InputOption::VALUE_REQUIRED, 'Number of deterministic keys to target', 100)
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Number of iterations to run (0 = infinite)', 0)
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay (sec) between iterations', 0.0)
            ->addOption('grace-period', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for cache invalidation', 1.0)
            ->addOption('retry-delay', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between repeated relay reads', 0.01)
            ->addOption('value-size', null, InputOption::VALUE_REQUIRED, 'Maximum length of generated values', 48)
            ->addOption('kill-mode', null, InputOption::VALUE_REQUIRED, 'One of "client" or "worker"', self::KILL_MODE_CLIENT)
            ->addOption('signal', null, InputOption::VALUE_REQUIRED, 'Signal to send when kill-mode=worker', 'SIGKILL')
            ->addOption('failure-log', null, InputOption::VALUE_REQUIRED, 'Optional file/dir path for JSON failure logs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $redisHost = (string) $input->getOption('redis-host');
        $redisPort = (int) $input->getOption('redis-port');
        $writers = $this->parseWriters((string) $input->getOption('writers'));
        $keyPrefix = (string) $input->getOption('key-prefix');
        $keyCount = max(1, (int) $input->getOption('keys'));
        $iterationLimit = (int) $input->getOption('iterations');
        $delay = max(0.0, (float) $input->getOption('delay'));
        $grace = max(0.0, (float) $input->getOption('grace-period'));
        $retryDelay = max(0.0, (float) $input->getOption('retry-delay'));
        $valueSize = max(16, (int) $input->getOption('value-size'));
        $killMode = $this->normalizeKillMode((string) $input->getOption('kill-mode'));
        $signalInput = (string) $input->getOption('signal');
        $signal = $this->parseSignal($signalInput);
        $failureLog = $input->getOption('failure-log');
        $seed = $this->initializeSeed($input->getOption('seed'));

        $redis = new \Redis(['host' => $redisHost, 'port' => $redisPort]);
        $endpoint = new CmdEndpoint($host, $port);
        $commandStats = new CommandStatsEndpoint($host, $port);

        $io->title('Relay deterministic kill fuzzer');
        $io->definitionList(
            ['Endpoint' => sprintf('%s:%d', $host, $port)],
            ['Writers' => implode(', ', $writers)],
            ['Key Prefix' => $keyPrefix],
            ['Logical Keys' => $keyCount],
            ['Delay' => sprintf('%.3f sec', $delay)],
            ['Grace Period' => sprintf('%.3f sec', $grace)],
            ['Retry Delay' => sprintf('%.3f sec', $retryDelay)],
            ['Kill Mode' => $killMode === self::KILL_MODE_CLIENT ? 'Redis CLIENT KILL' : sprintf('Worker signal (%s)', strtoupper($signalInput))],
            ['Value Length Limit' => sprintf('%d chars', $valueSize)],
            ['Seed' => $seed]
        );
        $io->newLine();

        $iteration = 0;
        $retries = 0;
        $staleReads = 0;
        $delayUs = (int) ($delay * 1_000_000);
        $lastTick = microtime(true);
        $start = $lastTick;

        while ($iterationLimit === 0 || $iteration < $iterationLimit) {
            $iteration++;
            $result = $this->runIteration(
                $endpoint,
                $commandStats,
                $redis,
                $writers,
                $keyPrefix,
                $keyCount,
                $valueSize,
                $killMode,
                $signal,
                $grace,
                $retryDelay
            );

            if ($io->isVerbose()) {
                $this->renderIterationTrace($io, $iteration, $result);
            }

            $retries += $result['retries'];
            $staleReads += $result['staleReads'];

            if (!$result['success']) {
                $this->reportFailure($io, $result, $failureLog);
                return Command::FAILURE;
            }

            if (($now = microtime(true)) - $lastTick >= 1) {
                $this->renderTick($io, $iteration, $start, $now, $retries, $staleReads);
                $lastTick = $now;
            }

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{
     *   success: bool,
     *   retries: int,
     *   staleReads: int,
     *   steps: array<int, array<string, mixed>>,
     *   key: string,
     *   expected: mixed,
     *   observed: mixed,
     * }
     */
    private function runIteration(
        CmdEndpoint $endpoint,
        CommandStatsEndpoint $commandStats,
        \Redis $redis,
        array $writers,
        string $keyPrefix,
        int $keyCount,
        int $valueSize,
        string $killMode,
        int $signal,
        float $grace,
        float $retryDelay
    ): array {
        $steps = [];
        $operation = $this->pickOperationProfile();
        $operationType = $operation['type'];
        $key = $this->pickKey($keyPrefix, $keyCount, $operation['prefix']);

        $initialWriter = $writers[array_rand($writers)];
        $initialValue = $this->generateOperationValue($operationType, $valueSize);
        $this->execOrThrow($endpoint, $initialWriter, 'del', [$key], $steps, 'reset-initial');
        $this->execOrThrow(
            $endpoint,
            $initialWriter,
            $operation['write'],
            $this->buildWriteArgsForOperation($operationType, $key, $initialValue),
            $steps,
            'write-initial'
        );

        $readArgs = $this->buildReadArgsForOperation($operationType, $key);
        $firstRead = $this->execOrThrow($endpoint, 'relay', $operation['read'], $readArgs, $steps, 'prime-read', [
            'source' => 'redis',
        ]);
        $initialReadCalls = $this->fetchCommandCallCount($commandStats, $operation['read'], $steps, 'commandstats-prime', false);
        $this->execOrThrow($endpoint, 'relay', $operation['read'], $readArgs, $steps, 'cache-read', [
            'source' => 'cache',
        ]);
        $postCacheReadCalls = $this->fetchCommandCallCount($commandStats, $operation['read'], $steps, 'commandstats-cache', false);
        if ($postCacheReadCalls !== $initialReadCalls) {
            throw new \RuntimeException(sprintf(
                'Expected relay cache hit for key %s but %s command count changed from %d to %d.',
                $key,
                strtoupper($operation['read']),
                $initialReadCalls,
                $postCacheReadCalls
            ));
        }
        $clientId = (int) ($firstRead['client_id'] ?? 0);
        $workerPid = (int) ($firstRead['pid'] ?? 0);

        if ($killMode === self::KILL_MODE_CLIENT) {
            if ($clientId <= 0) {
                throw new \RuntimeException('Relay response did not include a valid client id.');
            }
            $killResult = $redis->rawCommand('CLIENT', 'KILL', 'ID', (string) $clientId);
            if ($killResult === false || $killResult === 0 || $killResult === '0') {
                throw new \RuntimeException(sprintf('Failed to kill client id %d', $clientId));
            }
            $this->recordStep($steps, 'kill-client', 'redis', 'CLIENT KILL ID', [$clientId], ['result' => $killResult]);
        } else {
            $killSuccess = $this->killWorker($workerPid, $signal);
            $this->recordStep($steps, 'kill-worker', 'posix', 'kill', [$workerPid, $signal], ['result' => $killSuccess]);
        }

        $updatedValue = $this->generateOperationValue($operationType, $valueSize);
        $expectedNormalized = $this->normalizeOperationValue($operationType, $updatedValue);
        if ($expectedNormalized === null) {
            throw new \RuntimeException(sprintf('Unable to normalize expected value for operation "%s".', $operationType));
        }
        $nextWriter = $writers[array_rand($writers)];
        $this->execOrThrow($endpoint, $nextWriter, 'del', [$key], $steps, 'reset-updated');
        $this->execOrThrow(
            $endpoint,
            $nextWriter,
            $operation['write'],
            $this->buildWriteArgsForOperation($operationType, $key, $updatedValue),
            $steps,
            'write-updated'
        );

        $retries = 0;
        $staleReads = 0;
        $deadline = microtime(true) + $grace;
        $last = null;

        do {
            $last = $this->execOrThrow(
                $endpoint,
                'relay',
                $operation['read'],
                $readArgs,
                $steps,
                'verify-read',
                ['attempt' => $retries + 1]
            );
            if ($this->valuesMatch($operationType, $last['result'] ?? null, $expectedNormalized)) {
                return [
                    'success' => true,
                    'retries' => $retries,
                    'staleReads' => $staleReads,
                    'steps' => $steps,
                    'key' => $key,
                    'expected' => $updatedValue,
                    'observed' => $last['result'] ?? null,
                ];
            }

            $staleReads++;
            $retries++;

            if ($grace <= 0.0) {
                break;
            }

            if ($retryDelay > 0.0) {
                usleep((int) ($retryDelay * 1_000_000));
            }
        } while (microtime(true) < $deadline);

        return [
            'success' => false,
            'retries' => $retries,
            'staleReads' => $staleReads,
            'steps' => $steps,
            'key' => $key,
            'expected' => $updatedValue,
            'observed' => $last['result'] ?? null,
        ];
    }

    private function execOrThrow(
        CmdEndpoint $endpoint,
        string $class,
        string $command,
        array $args,
        array &$steps,
        ?string $action = null,
        array $meta = []
    ): array {
        $response = $endpoint->dispatch($class, $command, $args);
        if (($response['status'] ?? null) !== 'ok') {
            $this->recordStep($steps, $action ?? $command, $class, $command, $args, [
                'response' => $response,
                'meta' => $meta,
            ]);
            throw new \RuntimeException(sprintf('Command %s:%s failed: %s', $class, $command, json_encode($response)));
        }

        $this->recordStep(
            $steps,
            $action ?? $command,
            $class,
            $command,
            $args,
            array_merge(['response' => $response], $meta)
        );

        return $response;
    }

    private function fetchCommandCallCount(
        CommandStatsEndpoint $endpoint,
        string $command,
        array &$steps,
        string $action,
        bool $recordStep = true
    ): int {
        $stats = $endpoint->fetch();
        if (($stats['status'] ?? null) === 'error') {
            $message = sprintf('Command stats endpoint error: %s', $stats['error'] ?? 'unknown');
            $this->recordStep($steps, $action, 'commandstats', 'commandstats', [$command], [
                'response' => ['result' => null],
                'error' => $message,
            ]);
            throw new \RuntimeException($message);
        }

        $normalized = strtolower($command);
        if (!isset($stats[$normalized]) || !is_array($stats[$normalized])) {
            $this->recordStep($steps, $action, 'commandstats', 'commandstats', [$normalized], [
                'response' => ['result' => null],
            ]);
            throw new \RuntimeException(sprintf('Command stats missing entry for "%s".', $normalized));
        }

        $record = $stats[$normalized];
        if (!array_key_exists('calls', $record)) {
            $this->recordStep($steps, $action, 'commandstats', 'commandstats', [$normalized], [
                'response' => ['result' => $record],
            ]);
            throw new \RuntimeException(sprintf('Command stats entry for "%s" is missing call count.', $normalized));
        }

        $calls = (int) $record['calls'];

        if ($recordStep) {
            $this->recordStep(
                $steps,
                $action,
                'commandstats',
                'commandstats',
                [$normalized],
                ['response' => ['result' => $calls]]
            );
        }

        return $calls;
    }

    private function recordStep(
        array &$steps,
        string $action,
        string $class,
        string $command,
        array $args,
        array $extra = []
    ): void {
        $steps[] = array_merge([
            'ts' => microtime(true),
            'action' => $action,
            'class' => $class,
            'command' => $command,
            'args' => $args,
        ], $extra);
    }

    private function pickKey(string $prefix, int $count, string $typePrefix): string
    {
        $parts = [];
        if ($typePrefix !== '') {
            $parts[] = $typePrefix;
        }
        if ($prefix !== '') {
            $parts[] = $prefix;
        }
        $base = $parts === [] ? 'key' : implode(':', $parts);

        return sprintf('%s:%d', $base, mt_rand(1, $count));
    }

    /**
     * @return array{type: string, prefix: string, write: string, read: string}
     */
    private function pickOperationProfile(): array
    {
        $index = array_rand(self::OPERATION_PROFILES);

        return self::OPERATION_PROFILES[$index];
    }

    private function generateOperationValue(string $type, int $valueSize): mixed
    {
        return match ($type) {
            'string' => $this->nextValue($valueSize),
            'set' => $this->generateCollectionValues($valueSize, 1, 4),
            'list' => $this->generateCollectionValues($valueSize, 1, 4),
            'hash' => $this->generateHashValues($valueSize, 2, 4),
            default => throw new \InvalidArgumentException(sprintf('Unknown operation type "%s".', $type)),
        };
    }

    /**
     * @return array<int, string>
     */
    private function generateCollectionValues(int $valueSize, int $min, int $max): array
    {
        if ($max < $min) {
            $max = $min;
        }
        $count = mt_rand($min, $max);
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->nextValue($valueSize);
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function generateHashValues(int $valueSize, int $min, int $max): array
    {
        if ($max < $min) {
            $max = $min;
        }
        $count = mt_rand($min, $max);
        $hash = [];

        for ($i = 1; $i <= $count; $i++) {
            $hash[sprintf('field:%d', $i)] = $this->nextValue($valueSize);
        }

        return $hash;
    }

    /**
     * @return array<int, mixed>
     */
    private function buildWriteArgsForOperation(string $type, string $key, mixed $value): array
    {
        return match ($type) {
            'string' => [$key, (string) $value],
            'set', 'list' => array_merge([$key], array_values((array) $value)),
            'hash' => [$key, $value],
            default => throw new \InvalidArgumentException(sprintf('Unknown operation type "%s".', $type)),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function buildReadArgsForOperation(string $type, string $key): array
    {
        return match ($type) {
            'list' => [$key, 0, -1],
            default => [$key],
        };
    }

    private function valuesMatch(string $type, mixed $observed, mixed $expectedNormalized): bool
    {
        $normalized = $this->normalizeOperationValue($type, $observed);
        if ($normalized === null) {
            return false;
        }

        return $normalized === $expectedNormalized;
    }

    private function normalizeOperationValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            'string' => is_string($value) ? $value : null,
            'set' => $this->normalizeSetValue($value),
            'list' => $this->normalizeListValue($value),
            'hash' => $this->normalizeHashValue($value),
            default => null,
        };
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeSetValue(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $normalized = array_map(
            static fn ($item): string => is_string($item) ? $item : (string) $item,
            array_values($value)
        );
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeListValue(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return array_map(
            static fn ($item): string => is_string($item) ? $item : (string) $item,
            array_values($value)
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeHashValue(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $field => $item) {
            $normalized[(string) $field] = is_string($item) ? $item : (string) $item;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function nextValue(int $size): string
    {
        $pid = getmypid();
        if (!is_int($pid) || $pid <= 0) {
            $pid = 0;
        }

        $value = sprintf('value:%d:%d', $pid, ++$this->valueSequence);

        if ($size <= 0) {
            return $value;
        }

        if (strlen($value) > $size) {
            return substr($value, 0, $size);
        }

        return $value;
    }

    private function renderTick(
        SymfonyStyle $io,
        int $iteration,
        float $start,
        float $now,
        int $retries,
        int $staleReads
    ): void {
        $io->section(sprintf('Iteration %d', $iteration));
        $io->definitionList(
            ['Rate' => sprintf('%.2f iterations/sec', $iteration / max(0.001, $now - $start))],
            ['Retries' => $retries],
            ['Stale Reads' => $staleReads]
        );
        $io->newLine();
    }

    /**
     * @param array{
     *   key?: string,
     *   retries?: int,
     *   staleReads?: int,
     *   steps: array<int, array<string, mixed>>
     * } $result
     */
    private function renderIterationTrace(SymfonyStyle $io, int $iteration, array $result): void
    {
        $header = sprintf(
            '<fg=cyan>Iteration %d</> <fg=white>(Key: %s)</>',
            $iteration,
            $result['key'] ?? 'n/a'
        );
        if (isset($result['retries'], $result['staleReads'])) {
            $header .= sprintf(
                ' <fg=white>[retries: %d, stale reads: %d]</>',
                $result['retries'],
                $result['staleReads']
            );
        }

        $io->writeln($header);
        foreach ($result['steps'] as $step) {
            $io->writeln(sprintf('  %s', $this->describeStep($step)));
        }
        $io->newLine();
    }

    /**
     * @param array<string, mixed> $step
     */
    private function describeStep(array $step): string
    {
        $category = $this->categorizeStep($step);
        [$label, $color] = $this->labelStyleForCategory($category);
        $attempt = (int) ($step['attempt'] ?? 1);
        $labelText = $label;

        if ($category === 'read' && $attempt > 1) {
            $labelText .= sprintf(' (attempt %d)', $attempt);
        }

        $line = sprintf('<fg=%s>%s</>', $color, $labelText);
        $command = $this->describeCommandSegment($step, $category);
        if ($command !== '') {
            $line .= ' - ' . $command;
        }
        $meta = $this->describeMetaSegment($step, $category);
        if ($meta !== '') {
            $line .= ' ' . $meta;
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function categorizeStep(array $step): string
    {
        return match ($step['action'] ?? '') {
            'write-initial', 'write-updated', 'reset-initial', 'reset-updated' => 'write',
            'prime-read', 'verify-read', 'cache-read' => 'read',
            'kill-client', 'kill-worker' => 'kill',
            default => 'command',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function labelStyleForCategory(string $category): array
    {
        return match ($category) {
            'write' => ['WRITE', 'green'],
            'read' => ['READ', 'blue'],
            'kill' => ['KILL', 'red'],
            default => ['STEP', 'white'],
        };
    }

    /**
     * @param array<string, mixed> $step
     */
    private function describeCommandSegment(array $step, string $category): string
    {
        $command = strtoupper((string) ($step['command'] ?? ''));
        $args = is_array($step['args'] ?? null) ? $step['args'] : [];
        $response = is_array($step['response'] ?? null) ? $step['response'] : [];

        return match ($category) {
            'write' => $this->describeWriteCommand($command, $args),
            'read' => sprintf(
                '%s %s => %s',
                $command,
                $this->formatValue($args[0] ?? null),
                $this->formatValue($response['result'] ?? null)
            ),
            'kill' => $this->describeKillCommand($command, $args, $step, $response),
            default => $this->describeGenericCommand($command, $args, $step, $response),
        };
    }

    /**
     * @param array<int, mixed> $args
     * @param array<string, mixed> $step
     * @param array<string, mixed> $response
     */
    private function describeKillCommand(string $command, array $args, array $step, array $response): string
    {
        $argString = trim(implode(' ', array_map(
            fn ($arg) => $this->formatValue($arg, false),
            $args
        )));
        if ($argString !== '') {
            $command .= ' ' . $argString;
        }

        $result = $step['result'] ?? $response['result'] ?? null;
        if ($result !== null) {
            $command .= ' => ' . $this->formatValue($result, false);
        }

        return $command;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function describeWriteCommand(string $command, array $args): string
    {
        $key = $this->formatValue($args[0] ?? null);
        $value = $this->formatWriteValueForDisplay($command, $args);

        return sprintf('%s %s => %s', $command, $key, $value);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function formatWriteValueForDisplay(string $command, array $args): string
    {
        $upper = strtoupper($command);

        return match (true) {
            $upper === 'DEL' => '[n/a]',
            in_array($upper, ['SADD', 'RPUSH'], true) => $this->formatValue(array_slice($args, 1)),
            $upper === 'HMSET' => $this->formatValue($args[1] ?? null),
            default => $this->formatValue($args[1] ?? null),
        };
    }

    /**
     * @param array<int, mixed> $args
     * @param array<string, mixed> $step
     * @param array<string, mixed> $response
     */
    private function describeGenericCommand(string $command, array $args, array $step, array $response): string
    {
        $parts = [];
        foreach ($args as $arg) {
            $parts[] = $this->formatValue($arg);
        }
        $line = trim(sprintf('%s %s', $command, implode(', ', $parts)));
        $result = $response['result'] ?? $step['result'] ?? null;
        if ($result !== null) {
            $line .= ' => ' . $this->formatValue($result);
        }

        return trim($line);
    }

    /**
     * @param array<string, mixed> $step
     */
    private function describeMetaSegment(array $step, string $category): string
    {
        $parts = [];
        $response = is_array($step['response'] ?? null) ? $step['response'] : [];
        if (in_array($category, ['write', 'read'], true)) {
            $parts[] = sprintf('Client: %s', $this->formatClientName((string) ($step['class'] ?? '')));
        }
        if ($category === 'read' && isset($step['source'])) {
            $parts[] = sprintf('From: %s', $this->formatReadSource($step['source']));
        }

        if ($category === 'read' && $response !== []) {
            if (isset($response['client_id']) && $response['client_id'] !== null) {
                $parts[] = sprintf('Client ID: %s', $response['client_id']);
            }
            if (isset($response['pid']) && $response['pid'] !== null) {
                $parts[] = sprintf('Worker PID: %s', $response['pid']);
            }
        }

        if ($category === 'kill') {
            if (($step['action'] ?? '') === 'kill-worker') {
                if (isset($step['args'][0])) {
                    $parts[] = sprintf('Worker PID: %s', $step['args'][0]);
                }
                if (isset($step['args'][1])) {
                    $parts[] = sprintf('Signal: %s', $step['args'][1]);
                }
            } else {
                $parts[] = 'Client: Redis';
            }
        }

        if ($parts === []) {
            return '';
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private function formatClientName(?string $client): string
    {
        $normalized = strtolower((string) $client);

        return match ($normalized) {
            'relay' => 'Relay',
            'redis' => 'Redis',
            'posix' => 'POSIX',
            'commandstats' => 'Command Stats',
            default => ucfirst($normalized) ?: 'Unknown',
        };
    }

    private function formatReadSource(mixed $source): string
    {
        $normalized = strtolower((string) $source);

        return match ($normalized) {
            'cache' => 'Cache',
            'redis' => 'Redis',
            default => ucfirst($normalized) ?: 'Unknown',
        };
    }

    private function formatValue(mixed $value, bool $quoteStrings = true): string
    {
        if (is_string($value)) {
            $value = $this->truncateString($value, 80);
            return $quoteStrings
                ? "'" . addcslashes($value, "\\'") . "'"
                : addcslashes($value, "\\'");
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '[array]';
        }

        if (is_object($value)) {
            return sprintf('[%s]', get_debug_type($value));
        }

        return (string) $value;
    }

    private function truncateString(string $value, int $limit): string
    {
        if ($limit <= 3 || strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit - 3) . '...';
    }

    private function reportFailure(SymfonyStyle $io, array $result, ?string $failureLog): void
    {
        $io->error(sprintf(
            'Stale data detected for %s. Expected %s, observed %s after %d retries.',
            $result['key'],
            json_encode($result['expected'], JSON_UNESCAPED_SLASHES),
            json_encode($result['observed'], JSON_UNESCAPED_SLASHES),
            $result['retries']
        ));

        $payload = [
            'key' => $result['key'],
            'expected' => $result['expected'],
            'observed' => $result['observed'],
            'steps' => $result['steps'],
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($failureLog === null) {
            $io->writeln($encoded);
            return;
        }

        $path = $this->resolveLogPath($failureLog);
        file_put_contents($path, $encoded);
        $io->warning(sprintf('Failure log written to %s', $path));
    }

    private function resolveLogPath(string $target): string
    {
        $normalized = rtrim($target);
        if ($normalized === '') {
            return sprintf('failure-%s.json', date('Ymd-His'));
        }

        if (is_dir($normalized) || str_ends_with($normalized, DIRECTORY_SEPARATOR)) {
            $dir = rtrim($normalized, DIRECTORY_SEPARATOR);
            if ($dir === '') {
                $dir = '.';
            }
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create log directory %s', $dir));
            }
            return sprintf('%s/failure-%s.json', $dir, date('Ymd-His'));
        }

        $dir = dirname($normalized);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create log directory %s', $dir));
        }

        return $normalized;
    }

    private function killWorker(int $pid, int $signal): bool
    {
        if ($pid <= 0) {
            throw new \RuntimeException('Invalid worker PID returned from relay.');
        }

        if (!function_exists('posix_kill')) {
            throw new \RuntimeException('posix_kill is not available; cannot kill workers.');
        }

        if (!posix_kill($pid, $signal)) {
            throw new \RuntimeException(sprintf('Failed to send signal %d to pid %d', $signal, $pid));
        }

        return true;
    }

    private function initializeSeed(null|string $seedOption): int
    {
        if ($seedOption !== null) {
            $seed = (int) $seedOption;
        } else {
            $seed = random_int(1, PHP_INT_MAX);
        }

        mt_srand($seed);

        return $seed;
    }

    /**
     * @return array<int, string>
     */
    private function parseWriters(string $writers): array
    {
        $choices = array_filter(array_map('trim', explode(',', $writers)));
        if ($choices === []) {
            throw new \InvalidArgumentException('At least one writer must be provided.');
        }

        foreach ($choices as $choice) {
            if (!in_array($choice, self::VALID_WRITERS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid writer "%s". Allowed writers: %s',
                    $choice,
                    implode(', ', self::VALID_WRITERS)
                ));
            }
        }

        return array_values($choices);
    }

    private function normalizeKillMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            self::KILL_MODE_CLIENT, self::KILL_MODE_WORKER => $normalized,
            default => throw new \InvalidArgumentException('Kill mode must be "client" or "worker".'),
        };
    }

    private function parseSignal(string $signal): int
    {
        $signal = trim($signal);

        if ($signal === '') {
            throw new \InvalidArgumentException('Signal cannot be empty.');
        }

        if (is_numeric($signal)) {
            return (int) $signal;
        }

        $name = strtoupper($signal);
        if (!str_starts_with($name, 'SIG')) {
            $name = 'SIG' . $name;
        }

        if (!defined($name)) {
            throw new \InvalidArgumentException(sprintf('Unknown signal "%s".', $signal));
        }

        return (int) constant($name);
    }
}
