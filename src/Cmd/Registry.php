<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

use Mgrunder\Fuzzer\FuzzConfig;

class Registry {
    /** @var array<string, Cmd> */
    private array $commands;

    public function __construct(FuzzConfig $cfg, bool $flush, bool $del) {
        foreach (new \DirectoryIterator(__DIR__) as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if ( ! preg_match('/^([a-z]+)\.php$/', $file->getFilename(), $m)) {
                continue;
            }

            require_once __DIR__ . '/' . $file->getFilename();

            $class = 'Mgrunder\\Fuzzer\\Cmd\\' . ucfirst($m[1]);
            $obj = new $class($cfg);

            if (($obj->flags() & Cmd::FLUSH) && ! $flush)
                continue;

            if (($obj->flags() & Cmd::DEL) && ! $del)
                continue;

            $this->commands[$obj->name] = $obj;
        }
    }

    public function randomCmd(): Cmd {
        return $this->commands[array_rand($this->commands)];
    }

    /** @return string[] */
    public function commandNames(): array {
        return array_keys($this->commands);
    }

    /** @return Array<string, Cmd> */
    public function commands(): array {
        return $this->commands;
    }
}
