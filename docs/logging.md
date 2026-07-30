# Structured logging

Foundation resolves `Psr\Log\LoggerInterface`. Applications may bind any PSR-3
logger before the logging boundary is used.

The built-in logger supports:

- `null`: discard records;
- `file`: append JSON Lines;
- `error_log`: send JSON through PHP's error log.

Context redaction is recursive and matches configured case-insensitive key
fragments. Throwable normalization records class, code, file, and line.
Messages and traces are disabled by default because they may contain input,
database values, credentials, or tokens.

HTTP exception reporting is separate from response rendering. The reporter
receives operational status, method, path, request identifier, and normalized
exception context; the renderer independently maps safe domain exceptions to
HTTP responses. Expected exception classes can be excluded, sampling is opt-in,
and repeated exception signatures can be bounded per process and time window.
The throttle table is capped so long-lived workers cannot accumulate
unbounded signatures.

Logging is eager only in the web boundary where routing and error handling need
a logger. Console commands resolve it lazily only when a selected service
requires it.

See `resources/config/logging.php` for driver, path, level, redaction, message,
trace, exclusion, sampling, and throttling settings.
