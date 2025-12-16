<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

use Mgrunder\Fuzzer\FuzzConfig;
use Mgrunder\Fuzzer\HttpCmd;

abstract class Cmd extends HttpCmd {
    public const READ = (1 << 0);
    public const WRITE = (1 << 1);
    public const FLUSH = (1 << 2);

    abstract public function type(): Type;
    abstract public function flags(): int;
    abstract public function args(): array;

    /* We'll just have users name the class the command name */
    private function cmd(): string {
        $parts = explode('\\', static::class);
        return strtolower(end($parts));
    }

    public function fuzz(): array {
        $res = parent::exec($this->args());
        $res['args'] = $this->args();
        return $res;
    }

    public function __construct(protected FuzzConfig $cfg) {
        parent::__construct($cfg->host, $cfg->port, $this->cmd());
    }
}
