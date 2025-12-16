<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

use Mgrunder\Fuzzer\FuzzConfig;
use Mgrunder\Fuzzer\HttpRequest;

abstract class Cmd extends HttpRequest {
    private static $classes = ['redis', 'relay'];

    public const READ = (1 << 0);
    public const WRITE = (1 << 1);
    public const DEL = (1 << 2);
    public const FLUSH = (1 << 3);
    public const ADMIN = (1 << 4);

    abstract public function type(): Type;
    abstract public function flags(): int;
    abstract public function args(): array;

    public string $name {
        get => $this->cmd();
    }

    /* We'll just have users name the class the command name */
    private function cmd(): string {
        $parts = explode('\\', static::class);
        return strtolower(end($parts));
    }

    public function fuzz(): array {
        $args = [
            'cmd'  => $this->name,
            'args' => $this->args(),
        ];

        $res = ['query' => $args];

        $classes = match (!!($this->flags() & Cmd::READ)) {
            true  => self::$classes,
            false => [self::$classes[array_rand(self::$classes)]],
        };

        foreach ($classes as $class) {
            $args['class'] = $class;
            $res[$class] = parent::exec($args);
        }

        return $res;
    }

    public function __construct(protected FuzzConfig $cfg) {
        parent::__construct($cfg->host, $cfg->port, 'cmd.php');
    }
}
