# Quality gates

AabenIntra is sold to kommuner and handles personal data, so every change to
`main` passes the same automated checks. This page lists what the checks
enforce, how to run them locally and how to move the bars.

The checks live in `.github/workflows/ci.yml` and run on every pull request
and on every push to `main`. `.github/workflows/build-check.yml` (Docker image
builds) is unchanged and runs alongside.

## What each CI job enforces

| Job | Enforces | Red means |
| --- | --- | --- |
| `phpunit` | Unit, Kernel and Functional tests under `web/modules/custom/*/tests` pass on PHP 8.4 + SQLite; line coverage of `web/modules/custom` is at or above the gate in `ci.yml` | A test failed, or coverage dropped below the gate |
| `phpstan` | `vendor/bin/phpstan analyse` at level 6 with `phpstan-baseline.neon` reports nothing new | New code introduced a finding, or a baselined finding disappeared (remove it from the baseline) |
| `composer validate + audit` | `composer.json` is valid and `composer.lock` in sync; `composer audit --locked` finds no advisory | A dependency in the lock file has a published security advisory |
| `secret scan` | gitleaks over the full git history, rules from `.gitleaks.toml` | A credential-shaped string was committed (any commit, not only the tip) |
| `theme dist is current` | `npm ci && npm run build` in `web/themes/custom/aabenintra` reproduces the committed `dist/` byte for byte | A theme source or dependency change was committed without rebuilding `dist/` |

## Running the checks locally

### Prerequisites

Plain PHP: PHP 8.3 or 8.4 CLI with `pdo_sqlite`, Composer 2, Node 22.
`composer install` pulls `drupal/core-dev`, which brings PHPUnit, PHPStan with
the Drupal extension, and Drupal core's test base classes. With a PHP 8.3 CLI
add `--ignore-platform-req=php`, since `composer.lock` targets 8.4.

DDEV: the project has a `.ddev/config.yaml`; prefix each command with
`ddev exec` (or run them in `ddev ssh`). DDEV ships MariaDB, so point
`SIMPLETEST_DB` at it instead of SQLite:
`mysql://db:db@db/db`.

### Tests

```sh
# Unit tests: no database, no web server, a few seconds.
vendor/bin/phpunit --testsuite unit

# Kernel tests: a database. SQLite needs a writable web/sites/default/files.
mkdir -p web/sites/default/files web/sites/simpletest/browser_output
SIMPLETEST_DB=sqlite://localhost/sites/default/files/.ht.sqlite \
  vendor/bin/phpunit --testsuite kernel

# Functional tests: a database and a web server serving web/.
php -S 127.0.0.1:8888 -t web web/.ht.router.php &
SIMPLETEST_BASE_URL=http://127.0.0.1:8888 \
SIMPLETEST_DB=sqlite://localhost/sites/default/files/.ht.sqlite \
  vendor/bin/phpunit --testsuite functional

# One module, or one test.
vendor/bin/phpunit web/modules/custom/aabenintra_compliance
vendor/bin/phpunit --filter DashboardControllerTest
```

Under DDEV: `SIMPLETEST_BASE_URL=https://aabenintra.ddev.site` and
`SIMPLETEST_DB=mysql://db:db@db/db`.

Copy `phpunit.xml.dist` to `phpunit.xml` (gitignored) to hard-code these
environment values for your machine. Functional tests write the HTML of every
page they visit to `web/sites/simpletest/browser_output/` (gitignored).

### Coverage

```sh
# pcov (fast) or xdebug (XDEBUG_MODE=coverage) must be loaded.
SIMPLETEST_DB=sqlite://localhost/sites/default/files/.ht.sqlite \
  vendor/bin/phpunit --testsuite unit,kernel --coverage-clover build/clover.xml
php scripts/ci/coverage-gate.php build/clover.xml 10
```

`scripts/ci/coverage-gate.php` prints a per-module table and exits non-zero
when the total for `web/modules/custom` is below the threshold. Only custom
module code counts; test files, core and contrib are excluded by the
`<source>` filter in `phpunit.xml.dist`. Functional tests exercise a separate
PHP process (the web server), so their lines do not add to coverage; use Kernel
tests to cover controllers and services.

### Static analysis

```sh
vendor/bin/phpstan analyse                 # level 6 + baseline, same as CI
vendor/bin/phpstan analyse --level 9       # see what the end state asks for
```

### Dependencies and secrets

```sh
composer validate --no-check-publish
composer audit --locked
gitleaks git --config .gitleaks.toml .     # https://github.com/gitleaks/gitleaks
```

### Theme build

```sh
cd web/themes/custom/aabenintra
npm ci && npm run build
git status --porcelain -- dist             # must print nothing
```

Commit `dist/` with every change to `src/`, `vite.config.js` or
`package-lock.json` (Dependabot bumps of Vite included: the minifier output
changes between Vite majors).

## Writing tests

Tests live in `web/modules/custom/<module>/tests/src/{Unit,Kernel,Functional}`
with the namespace `Drupal\Tests\<module>\<Suite>`. Use PHPUnit attributes
(`#[CoversClass]`, `#[Group('<module>')]`), `declare(strict_types=1)` and
`final` classes like the rest of the codebase.

- Unit (`Drupal\Tests\UnitTestCase`): pure logic, mocked collaborators. Example:
  `aabenintra_compliance/tests/src/Unit/ReceiptServiceTest.php`.
- Kernel (`Drupal\KernelTests\KernelTestBase`): real database and container,
  no HTTP. Install only the schema and config a test needs
  (`installSchema`, `installEntitySchema`, `installConfig`). This is the right
  level for services, controllers and entity queries. Examples:
  `aabenintra_compliance/tests/src/Kernel/ReceiptServiceTest.php`,
  `aabenintra_dashboard/tests/src/Kernel/DashboardControllerTest.php`.
- Functional (`Drupal\Tests\BrowserTestBase`): full site install per test,
  slow. Reserve for routing, access and form round-trips. Example:
  `aabenintra_dashboard/tests/src/Functional/DashboardAccessTest.php`.

Modules are built from recipes and `hook_install()`, not config sync, so
Kernel tests create the content types and fields they need themselves rather
than importing config.

## Moving the bars

### Coverage gate

The threshold in `ci.yml` (`coverage-gate.php build/clover.xml <N>`) starts
where the suite stood when the gate was introduced, so it was green on day
one. Target: 60% of `web/modules/custom` (issue #112). Raise `N` in the same
pull request that adds the tests; never lower it to make a PR pass.

Priority order for new Kernel tests, by risk: `aabenintra_compliance`
controllers (acknowledgement + CSV report), `aabenintra_channels`
(`FollowService`), `aabenintra_directory` controllers, `aabenintra_theme`
(`PreferencesService` validation), then the Drush commands.

### PHPStan: from level 6 to level 9

`phpstan-baseline.neon` holds the findings that existed when the gate was
introduced (86 at level 6). The path:

1. Shrink the baseline to zero at level 6. Most entries are missing iterable
   value types (`array<string,mixed>` docblocks) and `EntityInterface` used
   where `FieldableEntityInterface`/`NodeInterface` is meant. Delete the
   matching baseline entries as you fix them; PHPStan fails on unmatched
   entries, so the baseline cannot silently rot.
2. Raise `level` in `phpstan.neon` one step at a time (7: union types, 8:
   nullable method calls on `StatementInterface|null` and `Request|null`,
   9: `mixed` handling in the database result rows). Regenerate the baseline
   only for the new level's findings
   (`vendor/bin/phpstan analyse --generate-baseline`), then shrink again.
3. Add `web/modules/custom/*/tests` to `paths` once the test code is clean at
   the chosen level.

`\Drupal::service()` calls inside classes are flagged by the Drupal extension;
inject the service instead, or in the one defensive case (optional module)
use `$container->has()` in `create()`.

## Security advisories and response time

`composer audit` fails the `composer` job for every advisory that matches
`composer.lock`, and `config.audit.block-insecure` in `composer.json` stops
`composer update` and `composer require` from resolving to a version with a
known advisory. `composer install` from a lock file is not blocked, so a
production rebuild keeps working while a fix is prepared.

The response times are defined in [SECURITY.md](../SECURITY.md). In short:
critical and high severity advisories (and every Drupal security advisory)
are patched and deployed as the next action, lower severities with the next
scheduled dependency bump. A red `composer` job is the trigger; Dependabot
(`.github/dependabot.yml`) opens the update pull request, or run
`composer update <vendor/package> --with-all-dependencies` by hand.

Docker base images are pinned by digest (`docker/Dockerfile`) and by major
(`docker-compose.yml`), so a rebuild never silently picks up a new upstream
image. Dependabot proposes the digest and tag bumps; review the upstream
changelog before merging.
