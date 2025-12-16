<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

enum Type: string {
    case STRING = 'string';
    case LIST = 'list';
    case SET = 'set';
    case HASH = 'hash';
    case ZSET = 'zset';
    case ANY = 'any';

    /* A helper to return a random type */
    public static function any(): Type {
        return match (rand(0, 4)) {
            0 => Type::STRING,
            1 => Type::LIST,
            2 => Type::SET,
            3 => Type::HASH,
            4 => Type::ZSET,
        };
    }
}

