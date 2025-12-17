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
well as `--show-last` to dump the last Redis/Relay payload every tick. You can
inspect the available fuzz commands and their groupings via:

```
bin/fuzzer --list-commands
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
