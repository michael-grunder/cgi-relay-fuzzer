<?php

namespace Mgrunder\Fuzzer;

require_once __DIR__ . '/' . '../vendor/autoload.php';

use Mgrunder\Fuzzer\Cmd\Type;

final class FuzzConfig {
    private int $iteration = 0;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly int $keys,
        public readonly int $members,
    ) {}

    private function keyName(Type $type): string {
        assert($type !== Type::ANY);
        return sprintf("%s:%d", $type->value, rand(1, $this->keys));
    }

    public function randomKey(Type $type): string {
        if ($type === Type::ANY)
            $type = Type::any();

        return $this->keyName($type);
    }

    public function randomKeys(Type $type): array {
        $keys = [];

        for ($i = 0; $i < $this->keys; $i++) {
            $keys[] = $this->randomKey($type);
        }

        return $keys;
    }

    public function nextValue(): string {
        return sprintf("value:%d", ++$this->iteration);
    }

    public function randomField(): string {
        return sprintf("field:%d", rand(1, $this->members));
    }

    public function randomFields(): array {
        $fields = [];

        for ($i = 0; $i < $this->members; $i++) {
            $fields[] = $this->randomField();
        }

        return $fields;
    }

    public function randomHash(): array {
        $hash = [];

        $mems = rand(1, $this->members);
        for ($i = 0; $i < $mems; $i++) {
            $hash[$this->randomField()] = $this->nextValue();
        }

        return $hash;
    }
}
