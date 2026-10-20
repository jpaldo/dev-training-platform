<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

# Code Quality Rules (always apply)

The codebase is partway through the cleanup and Tailwind migration tracked in `docs/CODE_QUALITY_PLAN.md`. These rules apply to every new or touched line, in every task, whether or not it is plan work. Do not re-read the plan just to learn them; open the plan only to check item status or to tick an item off.

## Target conventions

Some existing code does not meet these yet; that code is listed in the plan. Never copy a legacy pattern just because a sibling file uses it, and bring old code up to standard only through the plan, never through drive-by rewrites.

- **Validation:** every validated request uses a Form Request in `app/Http/Requests` (`Store…Request`, `Update…Request`, or an action name such as `DecideRecoveryRequestRequest`). No `$request->validate()` or `Validator::make()` in controllers. When moving existing rules, keep the rules and messages identical.
- **Authorization:** policies in `app/Policies`, the `role:` middleware, and `authorize()` in the Form Request when the check depends on the route model.
- **Controllers:** thin — resolve input, authorize, call a service, return a response. Target under ~150 lines; business logic belongs in `app/Services`. No Action classes and no repository layer.
- **Queries:** eager-load relationships, no queries inside loops, no database access from Blade views. Views receive prepared data from the controller or a service.
- **Types:** every method and closure has parameter and return types; PHPDoc array shapes for structured arrays.
- **Status and role values:** use the existing model constants (e.g. `InterviewScheduler::PENDING`, `GoogleDriveExport::FAILED`) and the `User` role helpers (`hasAnyRole`, `isCoachOrAdmin`). No new string literals where a constant exists. Do not introduce PHP enums until Phase 7, and do not start Phase 7 until the user explicitly asks.
- **File style:** no `declare(strict_types=1)` and rarely `final`; do not add them, so new files match their siblings.
- **Frontend JS:** shared behavior lives in `resources/js/app.js` as named initializers (see `initializePageTabs`), not in per-page scripts.

## Blade and Tailwind v4 (mid-migration)

- Converted views use the `x-ui.*` components in `resources/views/components/ui` (`card`, `button`, `badge`, `eyebrow`, `alert`, `tab-list`, `tab-panel`, `sidebar-link`) and the theme tokens in `resources/css/app.css` (`bg-canvas`, `bg-surface`, `bg-surface-sunken`, `bg-field`, `bg-raised`, `border-line`, `text-muted`, `text-subtle`, `brand`, `accent`, `danger`). Reuse them; do not hardcode the palette.
- No inline `style=""` except truly dynamic values (e.g. a progress width); no `@php` blocks beyond trivial presentation variables; readable multi-line markup.
- Preflight is disabled and the pre-Tailwind layout CSS lives in `@layer legacy` (until Phase 6). In converted markup, reset margins explicitly (`m-0` on headings, paragraphs and lists) and style buttons fully.
- Bare `grid` sizes its implicit column to content and can overflow on mobile; give single-column grids `grid-cols-1`.
- Never put a display utility (`grid`, `flex`, `block`) on an element that relies on the `hidden` attribute, such as a tab panel — wrap the content instead.
- Legacy views still use `class="grid"`, `.card`, `.btn` and similar. Do not mix legacy classes with Tailwind utilities on the same element.
- When converting a page, clean it up in the same pass: move database access and non-trivial `@php` logic out of the view, break single-line templates into readable markup, replace inline styles with utilities, and extract markup repeated on three or more pages into an `x-ui.*` component.
- Exact-markup test assertions exist (attribute order, `>Start<`). `x-ui.button` prints `class` last so passed attributes keep their order; keep slot text tight against the tags.
- An inline `@php(...)` placed before a `@php … @endphp` block in the same file is swallowed by the block and breaks compilation. Prefer block `@php … @endphp`.
- Mobile-first: verify layouts at phone width, keep focus states visible, and keep ARIA roles and labels correct.

## Working the plan

- "Continue the plan" means the first unchecked (`[ ]` or `[~]`) item in the lowest unfinished phase. Do only that batch, then stop.
- Keep each batch small and independently shippable: one concern, its tests, green suite. Behavior must not change unless the item says so.
- Work outside the plan follows these rules for the code it touches but does not refactor unrelated code. Record new problems under "Open decisions" in the plan instead of fixing them on the spot.
- Definition of done for a batch: `vendor/bin/pint --dirty --format agent`, the full suite passing (`php artisan test --compact`), `npm run build` if CSS, JS or Tailwind classes changed, and the plan item ticked (`[x]`, or `[~]` with a short note) with any drifted line numbers or counts corrected.
