<?php

declare(strict_types=1);

namespace Mgrunder\Fuzzer;

final class CommandStatsEndpoint extends HttpRequest
{
    public function __construct(string $host, int $port)
    {
        parent::__construct($host, $port, 'commandstats.php');
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        return $this->exec([]);
    }
}
