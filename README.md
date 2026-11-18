# WTF Happened?

**You were gone. Your code wasn't. Here's WTF happened.**

A small Laravel command for catching up on local Git history. See what changed, which Laravel areas and modules were touched, and what deserves a review. Git-only reporting works offline; AI interpretation from your chosen provider is optional.

Built for **#OneToolEveryWeek**. [GitHub repository](https://github.com/amrachraf6699/wtf-happened).

## Installation

Install the stable release from [Packagist](https://packagist.org/packages/amrachraf6699/wtf-happened) in your Laravel application:

```bash
composer require --dev amrachraf6699/wtf-happened
php artisan wtf:happened
```

Requirements: PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13; Composer; Git 2.37+ on PATH; a local Git working tree. PHP extensions required by Laravel and Guzzle must be available. CI covers the declared PHP/Laravel combinations; support depends on passing that matrix.

Laravel discovers the provider automatically. No manual provider registration or configuration publishing is needed. No application database, queue, scheduler, or frontend is added.

For local package development, add a Composer `path` repository pointing at this checkout and require `amrachraf6699/wtf-happened:@dev`.

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

## Optional AI setup

Set secrets in your application's untracked environment, never in tracked PHP:

```dotenv
WTF_HAPPENED_AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=your-key
WTF_HAPPENED_AI_MODEL=your-supported-model
```

Choose a provider and a model available to your account. There is no hardcoded model, automatic provider switching, or fallback model. The default provider remains `openai`, so existing OpenAI environment settings continue to work.

| Provider setting | API key environment variable | API |
| --- | --- | --- |
| `openai` | `OPENAI_API_KEY` | OpenAI Responses with strict structured output |
| `anthropic` | `ANTHROPIC_API_KEY` | Anthropic Messages, prompted JSON |
| `gemini` | `GEMINI_API_KEY` (or `GOOGLE_API_KEY`) | Google Gemini generateContent with JSON schema |
| `deepseek` | `DEEPSEEK_API_KEY` | Chat completions |
| `groq` | `GROQ_API_KEY` | Chat completions |
| `mistral` | `MISTRAL_API_KEY` | Chat completions |
| `xai` | `XAI_API_KEY` | Chat completions |
| `openrouter` | `OPENROUTER_API_KEY` | Chat completions |
| `together` | `TOGETHER_API_KEY` | Chat completions |
| `fireworks` | `FIREWORKS_API_KEY` | Chat completions |
| `cerebras` | `CEREBRAS_API_KEY` | Chat completions |
| `ollama` | Optional `OLLAMA_API_KEY` | Local OpenAI-compatible chat completions |
| `custom` | `WTF_HAPPENED_AI_API_KEY` | Your OpenAI-compatible endpoint |

Chat completions providers default to `response_format: json_object`. Model capabilities differ: set `WTF_HAPPENED_AI_RESPONSE_FORMAT=json_schema` for a model supporting strict JSON schema, or `prompt` for a compatible model that accepts neither response format. Prompt mode still requires valid JSON with the same fields and evidence checks. OpenAI requires a Responses/structured-output model; Gemini requires a generateContent/JSON-schema model. Anthropic receives the complete JSON contract in its system prompt. Reasoning models that cannot return the report within the bounded output budget may fail; choose a suitable text model. All provider paths are covered by mocked HTTP tests; live account/model availability is not tested.

`WTF_HAPPENED_AI_API_KEY` overrides the selected provider's key when explicitly set. Keys from other providers are never used as a fallback. If you previously published the configuration, merge the new settings before switching providers, especially changing the old `ai.api_key => env('OPENAI_API_KEY')` to `env('WTF_HAPPENED_AI_API_KEY')`. Otherwise that explicit old key would override your selected provider's key.

For local Ollama:

```dotenv
WTF_HAPPENED_AI_PROVIDER=ollama
WTF_HAPPENED_AI_MODEL=your-installed-model
```

The default endpoint is `http://localhost:11434/v1`. Pull/start the model yourself. To use another OpenAI-compatible service or gateway:

```dotenv
WTF_HAPPENED_AI_PROVIDER=custom
WTF_HAPPENED_AI_BASE_URL=https://your-gateway.example/v1
WTF_HAPPENED_AI_API_KEY=your-key
WTF_HAPPENED_AI_MODEL=your-model
WTF_HAPPENED_AI_RESPONSE_FORMAT=json_object
```

Include the API prefix in the base URL; the package appends `/chat/completions`. Endpoint overrides are accepted only for `custom` and `ollama`. HTTPS is required except for loopback hosts; API keys are optional only for local endpoints. URLs cannot contain credentials, query strings, or fragments, and redirects are disabled. Direct Azure, Amazon Bedrock, and Vertex authentication are not implemented; use a compatible gateway if needed.

```bash
php artisan wtf:happened --ai
```

An API key alone does **not** enable external requests. To explicitly authorize metadata transmission on future runs:

```dotenv
WTF_HAPPENED_AI_ENABLED=true
```

Before each enabled request, the command displays the selected provider, endpoint, and transmission scope (on stderr with `--json`). By default AI receives sanitized commit subjects, relative changed paths, categories, module labels, opaque evidence IDs, and counts. Contributor names/emails, remotes, absolute paths, full commit bodies, and working-tree contents are not included.

Separate explicit consent allows bounded committed PHP excerpts:

```dotenv
WTF_HAPPENED_AI_INCLUDE_DIFFS=true
```

This setting alone does not activate AI. `--no-ai` overrides both settings. If configuration is cached, rebuild the application's configuration cache after changing environment settings.

AI output is an interpretation, separate from deterministic facts. It cannot run tools or commands. Every generated statement must reference supplied evidence IDs, but references and JSON validation cannot prove factual correctness. Verify conclusions against Git.

## Privacy and limits

- No network calls by default, no telemetry, and no raw prompt or diff logging/persistence by this package.
- Excerpts are limited to PHP under `app/`, `src/`, `routes/`, `database/`, `tests/`, and recognized module/package/domain roots. Adding a custom module root extends this source allowlist, so scope it carefully. Category overrides do not bypass exclusions. Environment/configuration files, credential/key files, generated/dependency directories, binaries, symlinks, and submodules are excluded. Both rename paths are checked; moves across the application boundary get no excerpts.
- At most 10 excerpts, 2 KiB each, 20,000 bytes total. Blobs over 256 KiB are skipped. Patches contain one context line and may be truncated.
- Metadata and excerpt filtering redacts obvious secret assignments, bearer tokens, key blocks, credential URLs, and email addresses. **Automatic redaction cannot guarantee that all secrets are removed.** Commit subjects and filenames may themselves be sensitive.
- OpenAI Responses requests use `store: false`; this flag is specific to that API. Other providers use their own retention policies. No setting here guarantees zero provider retention; review your organization's data policy and your selected provider's applicable data controls before opting in. Hosted services and gateways may have additional routing/retention behavior.
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

File counts describe changed entries in analyzed commits, not current files or a net diff. A detected rename replaces its previous path in the count; deletion remains a changed entry. Merge comparisons can overlap side-branch changes. Line churn is not reported. Contributor counts use normalized email with name fallback; identities are never displayed. Review labels indicate changed paths, not detected vulnerabilities.

### Modular and custom Laravel layouts

Classification works from committed relative paths, case-insensitively. It recognizes conventional Laravel paths, nwidart's legacy and `app/`-nested module layouts, local packages, domain-oriented code, and Apiato-style containers. No module package installation is required, and application PHP is never loaded to determine categories.

Examples:

| Changed path | Category | Module |
| --- | --- | --- |
| `Modules/Blog/Http/Controllers/PostController.php` | Controllers | `Modules/Blog` |
| `Modules/Shop/app/Models/Order.php` | Models | `Modules/Shop` |
| `Modules/Shop/Database/Migrations/create_orders.php` | Migrations | `Modules/Shop` |
| `packages/acme/shop/src/Policies/OrderPolicy.php` | Policies | `packages/acme/shop` |
| `app/Domain/Orders/Actions/PlaceOrder.php` | Actions | `app/Domain/Orders` |
| `app/Containers/Shop/Order/Tasks/PlaceOrderTask.php` | Actions | `app/Containers/Shop/Order` |

Other categories include requests, API resources, events, listeners, providers, commands, notifications, mail, contracts, repositories, data objects, enums, validation rules, Livewire, Filament, views, translations, frontend assets, factories, dependencies, module definitions, infrastructure, documentation, and tests. `Entities` is treated as Models. Filename suffixes provide a fallback for familiar PHP roles. Paths that do not match remain `Other`; these are heuristics, not semantic code analysis.

Default module roots are `Modules/*`, `app/Modules/*`, `src/Modules/*`, `packages/*/*`, `packages/*`, `app/Containers/*/*`, `app/Domain/*`, `app/Domains/*`, `src/Domain/*`, `src/Domains/*`, `domain/*`, and `domains/*`. For another package or your own layout, publish the configuration and extend the roots or add full-path category rules:

```php
'analysis' => [
    'module_roots' => array_merge(
        \AmrAchraf\WtfHappened\Analysis\PathClassifier::ROOTS,
        ['components/*'],
    ),
    'category_rules' => [
        ['pattern' => 'components/*/Gateways/**', 'category' => 'Payment Gateways', 'review' => true],
        ['pattern' => 'Modules/*/Domain/ValueObjects/**', 'category' => 'Value Objects'],
    ],
],
```

`*` matches one nonempty path segment, `**` crosses segments, and `?` matches one character within a segment. First matching root/rule wins; rules override built-in categories. Omitted `review` defaults to false (auth/payment filename checks still apply). Explicit root/rule lists replace defaults, so merge `PathClassifier::ROOTS` as shown to extend them. Rules affect deterministic reports, review priority, and AI metadata together. Module counts use deduplicated changed-file entries, with at most eight modules shown in the terminal; JSON contains all modules.

Git analysis has a 30-second overall budget, ten-second process timeout, 2 MiB output limit per process, and 10 MiB total output limit. Completed commits are retained when analysis reaches a budget. A large history can reach the traversal limit before any commit is analyzed. Shallow boundaries are omitted rather than treated as root commits. Reports disclose these limitations and never imply complete totals.

## JSON interface

Schema version `1` contains `status`, `range`, `scope`, `completeness`, `statistics`, `changes`, `review_items`, `ai`, `warnings`, and `error`. Status is `ok`, `degraded`, or `error`. `completeness.history` and `completeness.ai_context` distinguish missing Git history from omitted AI context. AI status is `disabled`, `skipped_empty`, `succeeded`, or `failed`.

`changes.modules` maps relative module roots to changed-file counts; each `changes.files` entry also has a nullable `module`. `ai.provider` identifies the selected adapter after successful settings validation, otherwise it is null. These are additive fields in schema version 1.

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
