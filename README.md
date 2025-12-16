## Relay cgi based invalidation fuzzer

This is a quick and dirty fuzzer for Relay's new unified in-memory caching
mechanism. We want to find edge cases where we don't properly invalidate a key
or keys and return stale data.

