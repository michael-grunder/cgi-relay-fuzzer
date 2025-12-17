<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class strlen extends LenCmd {
    public function type(): Type {
        return Type::STRING;
    }
}
