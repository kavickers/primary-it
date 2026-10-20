# Upgrading from upstream Snipe-IT

This fork is [grokability/snipe-it](https://github.com/grokability/snipe-it) plus
Microsoft SQL Server support (see [microsoft-sql-server.md](microsoft-sql-server.md)).
Upgrading means merging a newer upstream release, re-checking everything that
touches SQL, and testing against SQL Server before deploying.

The October 2026 upgrade (v8.6.3 → v8.8.0, about 2,600 upstream commits)
turned up about 20 SQL Server problems, most of them in new upstream code, so
budget time for the testing steps, not just the merge.

| | |
| --- | --- |
| Upstream remote | `upstream` → https://github.com/grokability/snipe-it.git (`master` = latest release plus fixes) |
| Our remote | `origin` → https://github.com/kavickers/primary-it.git |
| Last merge | upstream `c9a696f31c` (v8.8.0 build 25378 plus 48 fixes), October 2026 |
| Tools | [`docs/upgrade-tools/`](upgrade-tools) |

## 1. What this fork changes

Knowing these is what makes a merge go quickly: they're where conflicts land,
and where upstream changes need a second look.

### Files that only exist here (never conflict)

| File | Purpose |
| --- | --- |
| `app/Services/SqlServer/SqlServerConnection.php` | Connection for the `sqlsrv` driver: trims fractional seconds, casts BIGINT to int, strips CHAR padding |
| `app/Services/SqlServer/SqlServerQueryGrammar.php` | `Y-m-d H:i:s` dates, `update top (n)`, drops repeated ORDER BY and SELECT columns |
| `app/Services/SqlServer/SqlServerQueryBuilder.php` | Inlines long integer `whereIn()` lists, chunks inserts over 2,100 parameters, `IDENTITY_INSERT`, unconvertible ids, NULL → `''`/`0` on NOT NULL updates |
| `app/Services/SqlServer/SqlServerSchemaGrammar.php` | `WITH VALUES` when adding a nullable column with a default |
| `app/Services/SqlServer/AggregateAliases.php`, `app/Models/Traits/InlinesAggregateAliases.php` | Inline `withCount()` / `withSum()` subqueries where SQL Server can't see the alias (HAVING, ORDER BY expressions) |
| `database/migrations/2026_10_16_072055_widen_custom_field_columns_on_sql_server.php` | Widens the legacy `string(255)` MAC Address custom field column so encrypted values fit |
| `tests/Feature/SqlServer/SqlServerCompatibilityTest.php`, `tests/Unit/SqlServer*Test.php` | Tests for the above (skipped unless running on SQL Server) |
| `tests/Feature/Console/FixMismatchedAssetsAndLogsTest.php` | Test for the fork's fix to that command |
| `docs/microsoft-sql-server.md`, this file, `docs/upgrade-tools/` | Docs and tooling |

### Upstream files we patch (may conflict)

Wiring:

- `app/Providers/AppServiceProvider.php`: registers `SqlServerConnection` for `sqlsrv` in `register()`.
- `config/database.php`: the `sqlsrv` connection (port, encryption, `SQLSRV_ATTR_FETCHES_NUMERIC_TYPE`).
- `config/backup.php`: leaves the database out of backups on SQL Server (no dumper exists).
- `.env.example`: the database section is SQL Server (`DB_CONNECTION=sqlsrv`, `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`) instead of MySQL. When upstream adds `DB_*` keys there, keep ours and only take the new ones that apply to SQL Server.

MySQL-only SQL in application code, each with a `sqlsrv` branch or a portable rewrite:

| File | What |
| --- | --- |
| `app/Helpers/Helper.php` | `checkLowInventory()` HAVING on count aliases → `havingAggregateAliases()` |
| `app/Http/Controllers/Api/AssetsController.php` | numeric sort on custom fields: `TRY_CAST(... AS FLOAT)` instead of `col * 1` |
| `app/Http/Controllers/Api/ReportsController.php` | `reportDateExpr()` for date grouping |
| `app/Http/Controllers/AssetModelsController.php`, `SettingsController.php` | `DATEADD` instead of `DATE_ADD(... INTERVAL ...)` |
| `app/Http/Controllers/Users/BulkUsersController.php` | no `insertOrIgnore()` |
| `app/Models/Builders/{Accessory,AssetModel,Component,Consumable,License}QueryBuilder.php` | sorts on count aliases go through `InlinesAggregateAliases` |
| `app/Models/Builders/UserQueryBuilder.php` | `CONCAT` quoting |
| `app/Models/Traits/Searchable.php` | `applyCountAliasFilter()` |
| `app/Console/Commands/DedupeAssets.php` | `havingRaw('COUNT(*) > 1')` |
| `database/seeders/DatabaseSeeder.php` | foreign key toggling per driver |

Migrations with SQL Server branches: `2013_11_21_024531`, `2015_09_22_003413`,
`2015_10_22_003314`, `2015_10_23_182625`, `2021_04_14_180125`, `2022_04_05_135340`,
`2023_07_13_052204`, `2025_03_06_152922`, `2026_04_20_200000`, `2026_05_19_000001`,
`2026_10_15_175207`, `2026_10_16_120000`. Upstream rarely edits old migrations, but if it does,
re-apply our branch.

Upstream bugs fixed here (not SQL Server-specific; drop our change if upstream fixes it):

- `app/Http/Controllers/SetupController.php`: `setupMigrate()` also creates the Passport clients when the database has none, not only when the key files are missing.
- `app/Console/Commands/FixMismatchedAssetsAndLogs.php`: only reads asset checkout logs (it matched consumables etc. with the same id and reassigned the asset).

Branding: the footer in `resources/views/layouts/default.blade.php` shows our
`general.footer_credit_mssql` (in `resources/lang/en-US/general.php`, so other locales fall
back to English) instead of `general.footer_credit`, without upstream's social media links.

Upstream tests adjusted for SQL Server: `ActionlogCompanyIdBackfillTest`,
`PendingAcceptanceQuantityOnCheckinTest`, `ImportAssetsTest` (row order),
`AdjustQuantityRaceConditionTest` (`updlock`), `StatusLabelColorInjectionTest`,
`ExtraFieldMappingTest`, `Unit/AssetModelTest`, `Setup/Ui/IndexSetupTest`.

To regenerate this list after a merge (compare against the upstream commit you merged):

```bash
git diff <merged-upstream-commit> --stat --ignore-cr-at-eol
```

## 2. One-time setup: a SQL Server test environment in Docker

Everything below runs in Docker, so nothing needs installing on the host
except Docker and Python 3. Commands are for Git Bash on Windows (hence
`MSYS_NO_PATHCONV=1` and `$(pwd -W)`); on Linux/macOS drop both.

```bash
cd /path/to/primary-it
docker network create snipeit-mssql-net

# SQL Server 2022 (Developer edition). Needs ~3.5 GB RAM.
docker run -d --name snipeit-mssql --network snipeit-mssql-net \
  -e ACCEPT_EULA=Y -e 'MSSQL_SA_PASSWORD=<a strong password>' \
  mcr.microsoft.com/mssql/server:2022-latest

# PHP + sqlsrv drivers, and a volume for vendor/ (keeps it off the host filesystem)
docker build -t snipeit-php-mssql -f docs/upgrade-tools/php-sqlsrv.Dockerfile docs/upgrade-tools
docker volume create snipeit-vendor

# helper: run a command in the PHP container with the repo mounted at /app.
# Leading "-e NAME=value" pairs become container environment variables.
#   dphp -e DB_DATABASE=snipeit_test -- php artisan migrate:status
dphp() {
  local envs=()
  while [ "$1" = "-e" ]; do envs+=(-e "$2"); shift 2; done
  [ "$1" = "--" ] && shift
  MSYS_NO_PATHCONV=1 docker run --rm --network snipeit-mssql-net \
    -v "$(pwd -W):/app" -v snipeit-vendor:/app/vendor -w /app "${envs[@]}" snipeit-php-mssql "$@"
}
```

Point a local `.env` at the container: `DB_CONNECTION=sqlsrv`, `DB_HOST=snipeit-mssql`,
`DB_USERNAME=sa`, `DB_PASSWORD=<the SA password>`, `DB_ENCRYPT=yes`,
`DB_TRUST_SERVER_CERTIFICATE=true`. Per-run settings (database name, mail,
debug) are passed as `-e` variables, which take precedence over `.env`.

Create the databases you'll need:

```bash
sql() { MSYS_NO_PATHCONV=1 docker exec snipeit-mssql /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P '<the SA password>' -Q "$1"; }
sql "CREATE DATABASE snipeit_test; CREATE DATABASE snipeit_e2e; CREATE DATABASE snipeit_upgrade;"
```

Keep a copy of production data for the upgrade-path test: back production up
with `BACKUP DATABASE`, copy the `.bak` into the container
(`docker cp prod.bak snipeit-mssql:/var/opt/mssql/data/`) and restore it as
`snipeit_upgrade` (`RESTORE DATABASE snipeit_upgrade FROM DISK = ... WITH MOVE ...`).
Keep the `.bak` so you can reset it between attempts.

**Gotchas on this machine**

- The repo lives in OneDrive. Writes from Docker occasionally fail with
  `EPERM` / "Permission denied" on `storage/framework/cache`; retrying works.
  Moving the repo off OneDrive avoids it.
- Claude Code (and some terminals) kill long-running background shells. Run
  the test suite in a detached container (`docker run -d`) instead.
- With `APP_ENV=local` and dev dependencies installed, every `migrate` (including
  the setup wizard's) runs upstream's `RegenerateIdeHelperAfterMigrate`
  listener, which rewrites model docblocks and writes `_ide_helper_models.php`
  (into `public/` when run from the web server). Use `APP_ENV=testing` for
  migrations, or `git checkout` the model files afterwards. Production installs
  (`composer install --no-dev`) don't have the listener.

## 3. Merge upstream

`upstream` is a source to pull from only: nothing from this fork is ever
pushed to grokability/snipe-it. Disable pushing to it in every clone:

```bash
git remote add upstream https://github.com/grokability/snipe-it.git   # if missing
git remote set-url --push upstream no_push_to_upstream
git remote -v    # upstream (push) must show no_push_to_upstream
```

Upgrades land on `master` as squash commits, which don't record the upstream
commit as a parent, so Git doesn't know how much of upstream is already
merged. Tell it, once per clone, before merging (local only, nothing is pushed):

```bash
# <squash commit> = the "Upgrade to Snipe-IT ..." commit on master
# <its parent>    = the master commit before it
# <upstream base> = the upstream commit it merged (see "Last merge" above)
git replace --graft <squash commit> <its parent> <upstream base>
# October 2026:
SQUASH=$(git log --format=%h --grep='^Upgrade to Snipe-IT v8.8.0' -1 master)
git replace --graft $SQUASH $SQUASH^ c9a696f31c
```

Without this, `git merge upstream/master` treats every upstream commit since
the original fork as new and conflicts everywhere.

```bash
git fetch upstream
git checkout master && git pull
git checkout -b upgrade/snipeit-vX.Y.Z
git log --oneline HEAD..upstream/master | wc -l     # how far behind
git merge --no-commit upstream/master               # or a release tag, e.g. v8.9.0
```

Commit the merge on its own (conflict resolutions only), then make SQL Server
fixes in separate commits: it keeps "what upstream changed" and "what we
changed" reviewable.

Resolving conflicts: for files in the "we patch" list above, take upstream's
version and re-apply our change on top (each is small and commented). For
everything else, take upstream's version. Don't commit generated noise:
`git diff --ignore-cr-at-eol` shows whether a modified file has real changes or
only line endings.

Then install dependencies inside the container:

```bash
dphp -- composer install
```

## 4. Review what upstream changed

Check whether Laravel itself moved (`composer.lock`: `laravel/framework`). Our
SQL Server classes override framework methods (`Connection::select()`,
`selectResultSets()`, `cursor()`; `Builder::where()`, `whereIn()`, `insert()`,
`insertGetId()`, `update()`; grammar `compileColumns()`,
`compileOrdersToArray()`, `compileUpdateWithoutJoins()`, `modifyDefault()`). If
a major Laravel upgrade came in, compare those methods' signatures and bodies
with the new framework versions first; the unit tests in `tests/Unit/SqlServer*`
will fail loudly if something drifted.

Then search the merged diff for SQL that only MySQL accepts:

```bash
BASE=<upstream commit of the previous merge>   # e.g. c9a696f31c
git diff $BASE upstream/master -- app database routes resources \
  | grep -nE '^\+.*(DB::raw|selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw|DB::statement|DB::select)' 
git diff $BASE upstream/master -- app database \
  | grep -nE '^\+.*(DATE_ADD|DATE_SUB|INTERVAL|IFNULL|GROUP_CONCAT|CONCAT_WS|FIND_IN_SET|LIMIT [0-9]|insertOrIgnore|upsert\(|FOREIGN_KEY_CHECKS|ON DUPLICATE|REGEXP|`)'
git diff --name-status $BASE upstream/master -- database/migrations   # new migrations
```

For each hit, ask: does it work on SQL Server? Common ones:

| MySQL | SQL Server |
| --- | --- |
| `DATE_ADD(d, INTERVAL n MONTH)` | `DATEADD(MONTH, n, d)` |
| `IFNULL(a, b)` | `COALESCE(a, b)` (works on both) |
| `GROUP_CONCAT` | `STRING_AGG` |
| `LIMIT n` in raw SQL | `TOP (n)` |
| backtick-quoted identifiers, double-quoted strings | `[identifiers]`, `'strings'` |
| `HAVING alias ...` / `ORDER BY alias + 1` on `withCount()` aliases | `InlinesAggregateAliases` / `AggregateAliases` |
| `insertOrIgnore()` | check-then-insert |
| `UPDATE ... JOIN` | `UPDATE ... FROM ... JOIN` |
| `MODIFY` / `CHANGE` column | schema builder `->change()` |
| dropping an indexed column / a column in the PK | drop the index or constraint first |
| adding a NOT NULL column to a non-empty table | needs a `->default()` |

Things the compatibility layer already covers (no action needed): fractional
seconds, BIGINT/CHAR values, repeated ORDER BY/SELECT columns, long `whereIn`
lists, large inserts, explicit ids, `limit()` on updates, unconvertible ids,
NULL into NOT NULL columns on update, `WITH VALUES` for new defaults. See
[microsoft-sql-server.md](microsoft-sql-server.md).

## 5. Migrate

Each of these must succeed; fix and repeat.

```bash
# Fresh install (empty database)
dphp -e APP_ENV=testing -e DB_DATABASE=snipeit_test -- php artisan migrate:fresh --force

# Rollback, then forward again
dphp -e APP_ENV=testing -e DB_DATABASE=snipeit_test -- php artisan migrate:rollback --step=40 --force
dphp -e APP_ENV=testing -e DB_DATABASE=snipeit_test -- php artisan migrate --force

# Upgrade path: a restored copy of production
dphp -e APP_ENV=testing -e DB_DATABASE=snipeit_upgrade -- php artisan migrate --force
```

Keep the rollback above `2026_06_30_000001_drop_company_name_unique_index`:
re-applying it after a rollback fails on every database because upstream's
`down()` is a no-op. Not ours to fix.

## 6. Run the test suite on SQL Server

About 5,300 tests; ~30 minutes with 3 processes. Run it detached and read the
results when it finishes:

```bash
mkdir -p ../snipeit-results
MSYS_NO_PATHCONV=1 docker run -d --name snipeit-tests --network snipeit-mssql-net \
  -v "$(pwd -W):/app" -v snipeit-vendor:/app/vendor -v "$(cd ../snipeit-results && pwd -W):/results" \
  -e DB_DATABASE=snipeit_test -w /app snipeit-php-mssql \
  sh -c 'php artisan test --parallel --processes=3 --recreate-databases --log-junit /results/junit.xml > /results/run.txt 2>&1'

docker logs -f snipeit-tests          # or: tail ../snipeit-results/run.txt
python docs/upgrade-tools/junit-failures.py ../snipeit-results/junit.xml
```

A single class: `dphp -e DB_DATABASE=snipeit_test -- vendor/bin/phpunit --filter SomeTest`.

**Is a failure ours or upstream's?** Rerun just the failing classes on SQLite
(what upstream's CI uses); anything that also fails there is upstream's or the
environment's:

```bash
python docs/upgrade-tools/junit-failures.py ../snipeit-results/junit.xml --classes \
  | sed 's/.*\\//' | paste -sd'|' > ../snipeit-results/failing.txt
dphp -e DB_CONNECTION=sqlite_testing -- vendor/bin/phpunit --filter "$(cat ../snipeit-results/failing.txt)"
```

Expected failures as of v8.8.0 (not bugs in this fork):

- `Unit/DatabaseSslConfigurationTest` (5 tests): needs the `pdo_mysql` extension, which the test image doesn't have.
- `Assets/Api/AssetHistoryTest::test_encrypted_custom_field_values_are_masked_for_users_without_encrypted_field_permission`:
  passes alone; in a full run a function-level `static` cache in `ActionlogsTransformer`
  holds custom fields from an earlier test. SQL Server never reuses identity
  values after a rollback, which exposes it.

Add tests for any compatibility fix you make (`tests/Feature/SqlServer/`,
`tests/Unit/SqlServer*`), and run Pint on changed files:
`dphp -- vendor/bin/pint --dirty --format agent`.

## 7. Test the running application

The test suite misses things only a browser finds (the setup wizard, form
saves, sorting every column). Run a throwaway site against an empty database:

```bash
sql "DROP DATABASE IF EXISTS snipeit_e2e; CREATE DATABASE snipeit_e2e;"
MSYS_NO_PATHCONV=1 docker run -d --name snipeit-e2e --network snipeit-mssql-net -p 127.0.0.1:8089:8000 \
  -v "$(pwd -W):/app" -v snipeit-vendor:/app/vendor -w /app/public \
  -e DB_DATABASE=snipeit_e2e -e APP_URL=http://127.0.0.1:8089 -e APP_ENV=local -e APP_DEBUG=true \
  -e DEBUGBAR_ENABLED=false -e MAIL_MAILER=log -e LOG_CHANNEL=daily -e API_THROTTLE_PER_MINUTE=100000 \
  -e PHP_CLI_SERVER_WORKERS=4 snipeit-php-mssql \
  php -S 0.0.0.0:8000 /app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

Use `127.0.0.1` (not `localhost`) so its cookies don't mix with other local sites.
Errors go to `storage/logs/laravel-YYYY-MM-DD.log`; check it after each area.

Checklist (everything here was exercised for v8.8.0):

1. **Setup wizard** at `/`: pre-flight, mail test, create tables, first admin
   with full multiple company support on. Then create an API key under
   Account → API keys (catches missing Passport clients).
2. **Reference data** through the UI: companies (with a parent), locations
   (with a parent), departments, suppliers, manufacturers, a category of each
   type (one with EULA + require acceptance), depreciation, status labels,
   custom fields (plain, encrypted, listbox, date) and a fieldset, models.
3. **Assets**: create (single, several tags at once, checked out on create),
   view, edit, clone, checkout to user / location / asset, checkin, audit,
   re-save after checkout and audit, maintenance (create, complete), file
   upload/download, labels, bulk edit / checkout / checkin / audit / delete,
   restore.
4. **Licenses**: seats to users and assets, checkin, reduce and increase seats,
   bulk checkout/checkin. **Accessories, consumables, components**: create,
   checkout, checkin, adjust quantity. **Kits**: add items, checkout.
5. **Users**: create, edit, bulk edit, merge, delete (blocked when holding
   items), restore, print, groups and permissions. Log in as a non-admin:
   accept an item (EULA PDF), request items, profile, API keys.
6. **Requests**: fulfil from `/requests`; calendar shows reservations.
7. **Import**: users and assets CSV (with auto-created models, categories, locations).
8. **Reports**: every page under `/reports`, the CSV exports, the custom
   asset report with each filter, saved report templates.
9. **Settings**: open and save every page under `/admin` unchanged, then set
   localization to `1.234,56` / `d.m.Y` and check an asset's cost displays and
   re-saves correctly. Backups (files only on SQL Server).
10. **REST API and SCIM** with a personal access token (create/update/checkout/
    checkin/audit/delete/restore; bad and huge ids should 404/422 cleanly).
11. **Console commands**: the alert and maintenance commands
    (`snipeit:expiring-alerts`, `inventory-alerts`, `upcoming-audits`,
    `expected-checkin`, `acceptance-reminder`, `user-inventory`,
    `validate-assets`, `counter-sync`, `sync-asset-locations`,
    `reconcile-calendar-events`, `purge --dry-run --force=true`, ...).

Then the two automated passes:

```bash
# every GET route, as the admin you created
dphp -e DB_DATABASE=snipeit_e2e -- php artisan route:list --json --method=GET > ../snipeit-results/routes.json
CRAWL_USER=<admin username> CRAWL_PASS=<admin password> \
  python docs/upgrade-tools/crawl.py http://127.0.0.1:8089 ../snipeit-results/routes.json ../snipeit-results/crawl.json
```

and paste [`docs/upgrade-tools/sort-sweep.js`](upgrade-tools/sort-sweep.js)
into the browser console while logged in as that admin (every table, every
sortable column, both directions).

Known upstream errors in both passes as of v8.8.0 (fail on MySQL too):
`account/display-sig/{file}` (missing `ProfileController::displaySig`),
`api/v1/imports/{id}` (no `ImportController::show`), `api/v1/labels/{unknown}`
(500 instead of 404), `kits/{id}/{accessories,consumables,licenses}/{id}/edit`
(Blade syntax errors; unused by the UI), sorting `api/v1/account/assets` by
`model_number`.

## 8. Commit, deploy

```bash
git add -A && git status        # check nothing generated slipped in (_ide_helper_models.php, public/_ide_helper*, storage/)
git commit                       # merge commit, then fix commits
git push -u origin upgrade/snipeit-vX.Y.Z
```

Production:

1. Back up the database with `BACKUP DATABASE` and copy the app directory
   (`.env`, `storage/`, `public/uploads`): Snipe-IT's own backups don't include
   a SQL Server database.
2. `php artisan down`
3. Deploy the code, then `composer install --no-dev --optimize-autoloader`.
4. `php artisan migrate --force`
5. `php artisan optimize:clear` (and `config:cache` / `route:cache` if you use them).
6. Restart PHP-FPM / IIS app pool / web server so workers pick up new code
   and any `php.ini` changes.
7. `php artisan up`, log in, open an asset, a report and the admin settings.

To roll back: restore the database backup and redeploy the previous commit.
Don't rely on `migrate:rollback` across a large upgrade.

Upstream's upgrade notes for each release are on
https://github.com/grokability/snipe-it/releases; read them for PHP version
bumps and required `.env` changes.
