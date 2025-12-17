<?php

namespace Mgrunder\Fuzzer;

class Trimmer {
    private \Redis $redis;

    public function __construct(private int $maxlen) {
        $this->redis = new \Redis(['host' => 'localhost', 'port' => 6379]);
    }

    private function getKeyTypes(): array {
        try {
            $keys  = $this->redis->keys('*');

            $this->redis->pipeline();
            foreach ($keys as $key) {
                $this->redis->type($key);
            }
            $types = $this->redis->exec();

            return array_combine($keys, $types);
        } catch (\Exception $ex) {
            echo "Redis connection error: " . $ex->getMessage() . "\n";
            return [];
        } finally {
            if ($this->redis->getMode() !== \Redis::ATOMIC) {
                $this->redis->discard();
            }
        }
    }

    private function trimString(string $key): int {
        $len = $this->redis->strlen($key);
        if ($len <= $this->maxlen)
            return 0;

        $val = $this->redis->getrange($key, 0, $this->maxlen - 1);

        $this->redis->set($key, $val);

        return $len - $this->maxlen;
    }

    private function trimList(string $key): int {
        $len = $this->redis->llen($key);
        if ($len <= $this->maxlen)
            return 0;

        $this->redis->ltrim($key, 0, $this->maxlen - 1);

        return $len - $this->maxlen;
    }

    private function trimSet(string $key): int {
        $len = $this->redis->scard($key);
        if ($len <= $this->maxlen)
            return 0;

        $members = $this->redis->srandmember($key, $len - $this->maxlen);
        $this->redis->srem($key, ...$members);

        return $len - $this->maxlen;
    }

    private function trimHash(string $key): int {
        $len = $this->redis->hlen($key);
        if ($len <= $this->maxlen)
            return 0;

        $fields = $this->redis->hkeys($key);
        $fieldsToRemove = array_slice($fields, $this->maxlen);
        $this->redis->hdel($key, ...$fieldsToRemove);

        return $len - $this->maxlen;
    }

    private function trimZSet(string $key): int {
        $len = $this->redis->zcard($key);
        if ($len <= $this->maxlen)
            return 0;

        $this->redis->zremrangebyrank($key, 0, $len - $this->maxlen - 1);

        return $len - $this->maxlen;
    }

    public function trim(): int {
        $total = 0;

        if ($this->maxlen < 1)
            return $total;

        try {
            $ktypes = $this->getKeyTypes();
            foreach ($ktypes as $key => $type) {
                $total += match($type) {
                    \Redis::REDIS_STRING => $this->trimString($key),
                    \Redis::REDIS_LIST => $this->trimList($key),
                    \Redis::REDIS_SET => $this->trimSet($key),
                    \Redis::REDIS_ZSET => $this->trimZSet($key),
                    \Redis::REDIS_HASH => $this->trimHash($key),
                    default => 0,
                };
            }
        } catch (\Exception $ex) {
            echo "Redis connection error: " . $ex->getMessage() . "\n";
            return $total;
        } finally {
            if ($this->redis->getMode() !== \Redis::ATOMIC) {
                $this->redis->discard();
            }
        }

        return $total;
    }
}
