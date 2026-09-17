# slowpoke/laravel

[Slowpoke](https://github.com/christiancannata/slowpoke-laravel) is a self-hosted tool that turns slow requests and
slow queries into technical debt with a price, measured in seconds of waiting per day, and helps your team pay it
back. This package is its Laravel integration: it needs the Slowpoke agent running on the same machine or network.

It tells Slowpoke which line of your Laravel app ran each query. For every HTTP request
and queued job it sends one trace to the Slowpoke agent on the same machine: the route, the status,
the timing and every query with its `file:line`. Slowpoke turns that into N+1 detection and missions
that point at your code, not at `vendor/`.

No OpenTelemetry extension or SDK needed. PHP 7.4+, Laravel 5.8 to 13.

## Install

```sh
composer require slowpoke/laravel
```

That is all: the service provider is auto-discovered and the agent listens on `127.0.0.1:4318` by default.
The agent needs an `otlp` source in `/etc/slowpoke/agent.yaml`:

```yaml
sources:
  - type: otlp          # listens on 127.0.0.1:4318
```

Optional: `php artisan vendor:publish --tag=slowpoke-config` copies `config/slowpoke.php`.

## Environment variables

| Variable | Default | |
|---|---|---|
| `SLOWPOKE_ENABLED` | `true` | `false` turns everything off: no listener, no middleware, nothing sent |
| `SLOWPOKE_OTLP_ENDPOINT` | `http://127.0.0.1:4318/v1/traces` | plain http to a local or private host only (IP in private ranges, `localhost`, single-label names like a Docker service, `.local`/`.internal`); anything else is ignored |
| `SLOWPOKE_TIMEOUT` | `0.1` | seconds to connect, then to hand over the trace; past that the trace is dropped |
| `SLOWPOKE_SERVICE` | `APP_NAME` | the name of this app in Slowpoke |
| `SLOWPOKE_JOBS` | `true` | trace queued jobs too |
| `SLOWPOKE_MAX_QUERIES` | `500` | queries described per request or job; the rest are only counted |
| `SLOWPOKE_MAX_SQL_LENGTH` | `10000` | longer statements are cut |
| `SLOWPOKE_BACKTRACE_LIMIT` | `60` | stack frames inspected to find the application line |
| `SLOWPOKE_CODE_ROOT` | `base_path()` | file paths are sent relative to it |

## What is sent, and what never is

Sent, as OTLP/JSON, only to the agent:
- per request: method, route template (`/orders/{id}`), status code, start and end time. When no route
  matched (a 404), the path without its query string;
- per job: the job class, the queue name, whether it failed;
- per query: the SQL **with placeholders** exactly as Laravel passes it to PDO, the database engine,
  the real duration, and the first application file and line on the stack (outside `vendor/` and this
  package; for a Blade view, the template path).

Never sent: binding values, request parameters, headers, cookies, session, user, exception messages.
If you write literal values into raw SQL yourself (`DB::select("... where email = 'a@b.c'")`), they are
part of the statement: the agent redacts them before anything leaves the machine.

## How it stays out of the way

- The trace is sent from a terminating callback: under php-fpm the response has already reached the
  client (`fastcgi_finish_request`). Queue workers send right after each job.
- Raw socket with a hard time budget (`SLOWPOKE_TIMEOUT`), every error swallowed: a missing, slow or
  broken agent costs one trace, never a request.
- `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)` with a bounded depth; queries outside a request or a job
  (artisan commands, worker polling) are not recorded at all.

## Tests

Everything runs in Docker, nothing to install on the host:

```sh
./bin/test 8.3                                # PHP 8.3, latest Laravel (13)
./bin/test 7.4                                # PHP 7.4, Laravel 8
./bin/test 7.4 'orchestra/testbench:3.8.*'    # PHP 7.4, Laravel 5.8
./bin/test 8.3 --filter OriginFinder          # extra arguments go to PHPUnit
UPDATE_FIXTURES=1 ./bin/test 8.3 --filter OtlpFixtures   # rewrite spec/laravel_otlp_fixtures.json
```

In the Slowpoke repository, `spec/laravel_otlp_fixtures.json` holds payloads exactly as this package sends them, with
what the agent must read from each; the agent's Go test replays them. In this standalone repository that contract test
is skipped.

## License

MIT, see [LICENSE](LICENSE).
