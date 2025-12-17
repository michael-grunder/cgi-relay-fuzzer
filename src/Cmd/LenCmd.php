<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

abstract class LenCmd extends KeyCmd {
    public function flags(): int {
        return self::READ;
    }
}
