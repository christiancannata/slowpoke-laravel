# Changelog

## 0.1.5 - 2026-09-19

- The versions this package allows no longer include ones with a known hole: the floors move to the
  patched releases of each branch (`illuminate/*` 5.8.35, 6.20.26, 7.30.6, 8.75) and PHPUnit starts
  at 8.5.52. Nothing changes in the code, and every combination of the matrix still passes; what
  changes is that `composer require slowpoke/laravel` can no longer resolve to a Laravel with the
  binding or the SQL Server LIMIT advisory against it.
- Weight is a test now: no runtime dependency of its own, an installed copy that is source and
  documentation only, and a wall on the size of `src/`.
- SECURITY.md says why two advisories stay open on the repository: they are about Laravel 5.8,
  which was never patched and is supported here on purpose, and not about this code.

## 0.1.4 - 2026-09-18

- Type documentation only: the scheduled task event had no declared type, which PHPStan level 6
  refuses. No change in behaviour.

## 0.1.3 - 2026-09-18

- Scheduled commands are traced like queued jobs: the command as written in the scheduler
  (`invoices:close`), how long it took, whether it failed, and the `file:line` of its queries.
  `SLOWPOKE_SCHEDULE=false` turns it off. The events are listened to by name, so no Laravel
  version is required to have the class.
- A trace says what it is (`slowpoke.kind`), so the agent files a job or a command under Jobs
  instead of among the endpoints. Before this, a queued job looked like a route named after its
  class, and nothing ever reached the panel's Jobs page.
- `bin/bench` measures what the package costs while a request is running, and the README carries
  the result: 0.09 to 0.10 ms on a request with fifty queries, about 2 µs each.
- README rewritten around what you get, what it costs, what is sent and what never is.

## 0.1.2 - 2026-09-17

- Supply chain and trust: OpenSSF Scorecard, PHPStan level 6 and `composer audit` in CI, CodeQL on the workflows,
  GitHub Actions pinned by commit, Dependabot, SECURITY.md with what the package does and never does, signed build
  provenance (Sigstore) attached to every release.
- No change in behavior: type documentation only.

## 0.1.1 - 2026-09-17

- Span timestamps are identical on every PHP version: PHP 8.4 changed `round()`, which moved some timestamps by a
  microsecond. Found by the new test matrix.
- Tests run on GitHub Actions for PHP 7.4 to 8.5 and Laravel 5.8 to 13, on every push and weekly.

## 0.1.0 - 2026-09-17

First release.

- One trace per HTTP request and queued job, sent to the local Slowpoke agent (OTLP/JSON) after the response.
- Route templates, status codes, job classes and queues.
- Every query with placeholders, its engine, duration and the application file and line that ran it
  (Blade views by template), skipping `vendor/` and the package itself.
- Hard 0.1 s budget, errors swallowed, plain http to local or private hosts only.
- PHP 7.4 to 8.x, Laravel 5.8 to 13.
