# Contributing

Keep changes small and useful to someone returning to a Laravel project.

1. Install PHP, Composer, and Git, then run `composer install`.
2. Add a regression test for behavior changes. Use disposable repositories and HTTP fakes; never real API keys or paid requests.
3. Run `composer test` and `composer lint`. Run `vendor/bin/pint` to format changes.
4. Explain the user-visible behavior and verification in your pull request.

Do not add telemetry, background services, or implicit AI transmission. Preserve read-only Git operations and explicit consent. Avoid putting real repository content or secrets in issues.

Before release: pass the CI matrix and fresh Laravel install smoke tests, review the distribution archive, update the changelog, and verify the README. Publishing, tagging, and pushing require maintainer authorization. A local passing test run is not evidence that every CI environment passed.
