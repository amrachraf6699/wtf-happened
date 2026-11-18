# WTF Happened?

**You were gone. Your code wasn't. Here's WTF happened.**

A small Laravel command for catching up on local Git history. See what changed, which Laravel areas were touched, and what deserves a review. Git-only reporting works offline; OpenAI interpretation is optional.

Built for **#OneToolEveryWeek**. [GitHub repository](https://github.com/amrachraf6699/wtf-happened).

## Installation

Once the first release is published on Packagist, install it in your Laravel application:

```bash
composer require --dev amrachraf6699/wtf-happened
php artisan wtf:happened
```

Requirements: PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13; Composer; Git 2.37+ on PATH; a local Git working tree. PHP extensions required by Laravel and Guzzle must be available. CI covers the declared PHP/Laravel combinations; support depends on passing that matrix.

Laravel discovers the provider automatically. No manual provider registration or configuration publishing is needed. No application database, queue, scheduler, or frontend is added.

For local package development before publication, add a Composer `path` repository pointing at this checkout and require `amrachraf6699/wtf-happened:@dev`.

## Quick start

```bash
php artisan wtf:happened
php artisan wtf:happened --days=30
php artisan wtf:happened --since=v1.0.0
php artisan wtf:happened --funny
php artisan wtf:happened --no-ai --json
```

Illustrative output, not an analysis of this repository:

```text
WTF HAPPENED?
Your code moved on without you.
Scope: .

THE DAMAGE REPORT
8 commits | 12 changed files | 3 contributors

WHAT CHANGED
Migrations: 1 file changed
Routes: 2 files changed
Commit: Add account settings routes

WORTH REVIEWING
database/migrations/... - Migrations changed; worth reviewing.

AI: DISABLED
```

Output uses color when the terminal supports it and plain ASCII otherwise. Use Laravel's standard `--no-ansi` option to disable color.

## Options

| Option | Behavior |
| --- | --- |
| `--days=N` | Last N days, 1–3650; defaults to 7 |
| `--since=REF` | Commits reachable from HEAD but not the specified ancestor commit/tag |
| `--funny` | Add a short fact-based joke; does not enable AI |
| `--ai` | Authorize external AI analysis for this run |
| `--no-ai` | Force offline analysis, overriding all AI settings |
| `--json` | Write one JSON object to stdout; diagnostics go to stderr |

`--days` and `--since` are mutually exclusive. A ref must be one local named reference or commit hash that resolves to an ancestor of HEAD. Revision expressions such as `HEAD~2` and ranges are rejected; supply the resolved hash instead. No remote fetch is attempted.

Exit codes: `0` for a report (including no matches or disclosed limits), `1` for Git/environment failure or enabled AI failure, and `2` for invalid options/configuration. AI failure preserves deterministic results with `status: degraded`.

## Optional OpenAI setup

Set secrets in your application's untracked environment, never in tracked PHP:

```dotenv
OPENAI_API_KEY=your-key
WTF_HAPPENED_AI_MODEL=your-supported-model
```

Choose a model available to your account that supports the Responses API and strict structured output. There is deliberately no hardcoded model or automatic fallback model.

```bash
php artisan wtf:happened --ai
```

An API key alone does **not** enable external requests. To explicitly authorize metadata transmission on future runs:

```dotenv
WTF_HAPPENED_AI_ENABLED=true
```

By default AI receives sanitized commit subjects, relative changed paths, categories, opaque evidence IDs, and counts. Contributor names/emails, remotes, absolute paths, full commit bodies, and working-tree contents are not included.

Separate explicit consent allows bounded committed PHP excerpts:

```dotenv
WTF_HAPPENED_AI_INCLUDE_DIFFS=true
```

This setting alone does not activate AI. `--no-ai` overrides both settings. If configuration is cached, rebuild the application's configuration cache after changing environment settings.

AI output is an interpretation, separate from deterministic facts. It cannot run tools or commands. Every generated statement must reference supplied evidence IDs, but references and JSON validation cannot prove factual correctness. Verify conclusions against Git.

## Privacy and limits

- No network calls by default, no telemetry, and no raw prompt or diff logging/persistence by this package.
- Excerpts are limited to PHP under `app/`, `routes/`, `database/`, and `tests/`. Environment/configuration files, credential/key files, generated/dependency directories, binaries, symlinks, and submodules are excluded. Both rename paths are checked; moves across the application boundary get no excerpts.
- At most 10 excerpts, 2 KiB each, 20,000 bytes total. Blobs over 256 KiB are skipped. Patches contain one context line and may be truncated.
- Metadata and excerpt filtering redacts obvious secret assignments, bearer tokens, key blocks, credential URLs, and email addresses. **Automatic redaction cannot guarantee that all secrets are removed.** Commit subjects and filenames may themselves be sensitive.
- OpenAI requests use `store: false`. This is not a guarantee of zero provider retention; review your organization's data policy and OpenAI's applicable data controls before opting in.
- One request, no automatic retry, five-second connection timeout, twenty-second overall HTTP timeout, 64 KiB response ceiling.
- Git commands are read-only and disable external diff/textconv execution, lazy fetching, replacement objects, pagers, and fsmonitor. The host Laravel application's normal boot behavior remains outside this package's control.

Optional configuration:

```bash
php artisan vendor:publish --tag=wtf-happened-config
```

The publishable file is `config/wtf-happened.php`. Defaults: seven days, 100 commits, 40,000 bytes of serialized AI evidence, 20,000 bytes of diff excerpts, and twenty-second AI timeout. Commit limits may be set to 1–1000; AI byte/time settings may be reduced within the validated ranges. No publication is needed for basic usage. Nested partial configuration retains defaults.

## How analysis works

1. Locate the repository containing Laravel's base directory and freeze HEAD.
2. Read reachable commits using committer timestamps, an absolute UTC cutoff, and an upper bound at command invocation. Unusual date ordering does not stop the time-window traversal early.
3. Compare each selected commit with its first parent; true root commits are compared with an empty tree. Include side branches and merge commits.
4. Keep only changed paths inside the Laravel application's subtree. Sibling projects and root files outside that subtree are excluded.
5. Deduplicate changed-file entries, count contributors locally, group directories, and apply deterministic Laravel path rules.
6. Optionally build a filtered evidence payload and request a validated AI interpretation.

File counts describe changed entries in analyzed commits, not current files or a net diff. A detected rename replaces its previous path in the count; deletion remains a changed entry. Merge comparisons can overlap side-branch changes. Line churn is not reported. Contributor counts use normalized email with name fallback; identities are never displayed. Categories cover controllers, middleware, models, policies, jobs, services, routes, migrations, seeders, configuration, and tests. Review labels indicate changed paths, not detected vulnerabilities.

Git analysis has a 30-second overall budget, ten-second process timeout, 2 MiB output limit per process, and 10 MiB total output limit. Completed commits are retained when analysis reaches a budget. A large history can reach the traversal limit before any commit is analyzed. Shallow boundaries are omitted rather than treated as root commits. Reports disclose these limitations and never imply complete totals.

## JSON interface

Schema version `1` contains `status`, `range`, `scope`, `completeness`, `statistics`, `changes`, `review_items`, `ai`, `warnings`, and `error`. Status is `ok`, `degraded`, or `error`. `completeness.history` and `completeness.ai_context` distinguish missing Git history from omitted AI context. AI status is `disabled`, `skipped_empty`, `succeeded`, or `failed`.

AI statements contain `text` and `evidence_ids`. The public evidence mapping includes subjects and relative paths, never excerpts. Report strings are redacted and control-character filtered; unusual filenames therefore may be represented differently than their original bytes.

## Limitations and roadmap

Only committed local history is analyzed. Metadata-only summaries depend on commit quality. Read budgets, shallow history, and privacy exclusions reduce context. No security scanner or automated bug detection is included. No live paid AI request is part of the test suite. Checkpoints (`--since-last`) are deferred to v0.2.0; they are not implemented.

## Development and contributing

```bash
composer install
composer test
composer lint
```

Tests use disposable Git repositories and mocked HTTP responses. See [CONTRIBUTING.md](CONTRIBUTING.md). A quick demo can show `php artisan wtf:happened --no-ai`, then `--funny`, then the optional AI setup notice, without exposing real project data.

Licensed under the [MIT License](LICENSE).
