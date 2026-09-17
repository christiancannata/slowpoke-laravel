# Changelog

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
