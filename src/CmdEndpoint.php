<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer;

/**
 * Small helper used by the deterministic fuzzer to talk to cmd.php without
 * going through the random fuzzing command registry.
 */
final class CmdEndpoint extends HttpRequest
{
    public function __construct(string $host, int $port)
    {
        parent::__construct($host, $port, 'cmd.php');
    }

    /**
     * Execute a command via cmd.php.
     *
     * @param string $class   relay|redis
     * @param string $command The command to run (set, get, etc)
     * @param array<int, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function dispatch(string $class, string $command, array $arguments): array
    {
        return $this->exec([
            'class' => $class,
            'cmd' => $command,
            'args' => array_values($arguments),
        ]);
    }
}
