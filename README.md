## Relay cgi based invalidation fuzzer

This is a quick and dirty fuzzer for Relay's new unified in-memory caching
mechanism. We want to find edge cases where we don't properly invalidate a key
or keys and return stale data.

## Usage

The entry point is the Symfony Console command exposed by `bin/fuzzer`. Run it
with `--help` to see every available option:

```
bin/fuzzer --help
```

Common flags include `--host`, `--port`, and `--kill` to control the target as
well as `--iterations` to run a finite number of loops and `--show-last` to dump
the last Redis/Relay payload every tick. You can inspect the available fuzz
commands and their groupings via:

```
bin/fuzzer --list-commands
```

### Deterministic cache invalidation fuzzer

`bin/kill-fuzzer` focuses on the specific generation invalidation workflow. It
writes deterministic keys, forces Relay to cache them, and then invalidates the
connection either by killing the Redis client ID or by signalling the worker
process ID that granted the cached reply. All of the knobs you would expect on
a fuzzer are available: RNG seeds, per-iteration delays, controllable writer
classes, value sizes, and configurable kill modes/signals. When stale data is
observed you can point `--failure-log` at a file or directory to capture a full
JSON log of every step.

Run a finite number of deterministic iterations with `--iterations=N` (default
of `0` keeps the loop running indefinitely).

Use `--types` to constrain the fuzzer to a subset of paired operations. For
example, `--types=string` limits iterations to SET/GET, while
`--types=hash,list` would only run the HMSET/HGETALL and RPUSH/LRANGE pairs.

Example invocation that targets worker exits with SIGKILL and emits logs to the
`var/log` directory:

```
bin/kill-fuzzer --kill-mode=worker --signal=SIGKILL --failure-log=var/log --delay=0.1
```

### Manually issuing commands

Use `bin/cmd` to talk to the `public/cmd.php` endpoint without juggling curl
invocations. It accepts the target client class, command, and any number of
arguments:

```
bin/cmd relay ping
bin/cmd redis set mykey myvalue
bin/cmd --url=http://127.0.0.1:8080/cmd.php relay del mykey otherkey
```
