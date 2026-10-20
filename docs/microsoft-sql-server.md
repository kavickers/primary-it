# Running Snipe-IT on Microsoft SQL Server

Upstream Snipe-IT targets MySQL/MariaDB. This fork also runs on **Microsoft SQL Server**
(tested against SQL Server 2022 and 2025, and should work on 2016+). MySQL/MariaDB remain fully
supported — the SQL Server support is additive and selected via `DB_CONNECTION=sqlsrv`.

## Requirements

- **PHP 8.2+** with these extensions enabled (in addition to the normal Snipe-IT
  requirements):
  - `sqlsrv` and `pdo_sqlsrv` — the Microsoft Drivers for PHP for SQL Server
  - `sodium` and `zip` (required by Snipe-IT generally; make sure they are enabled)
- **Microsoft ODBC Driver 18 for SQL Server** installed on the host running PHP.
- A SQL Server instance with **mixed-mode authentication** if you intend to use a SQL login.

On Windows, download the `php_sqlsrv`/`php_pdo_sqlsrv` DLLs that match your PHP version
and build: non-thread-safe (NTS) for IIS/FastCGI, thread-safe (TS) for Apache mod_php.
PHP 8.4 and 8.5 need driver 5.13 or later. Add them to `php.ini`, e.g. for PHP 8.5 on IIS:

```ini
extension=sodium
extension=zip
extension=php_sqlsrv_85_nts_x64.dll
extension=php_pdo_sqlsrv_85_nts_x64.dll
```

For a full IIS setup see [deploying-on-iis.md](deploying-on-iis.md).

> If you change `php.ini`, restart your web server (Apache/Nginx/FPM). FastCGI/mod_php
> workers cache `php.ini` at startup, so a running server will not pick up newly enabled
> extensions until it is restarted.

## Database setup

Create a database and a login (run as an administrator, e.g. via `sqlcmd`):

```sql
CREATE DATABASE snipeit;
GO
CREATE LOGIN snipeit WITH PASSWORD = 'a-strong-password', DEFAULT_DATABASE = snipeit;
GO
USE snipeit;
CREATE USER snipeit FOR LOGIN snipeit;
ALTER ROLE db_owner ADD MEMBER snipeit;
GO
```

## .env configuration

```dotenv
DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=1433
DB_DATABASE=snipeit
DB_USERNAME=snipeit
DB_PASSWORD='a-strong-password'
DB_PREFIX=null

# ODBC Driver 18 encrypts connections by default. For a local instance with a
# self-signed certificate, trust the server cert. In production with a properly
# signed certificate, set DB_TRUST_SERVER_CERTIFICATE=false.
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=true
```

## Install

```bash
php artisan key:generate
php artisan migrate
```

Then visit the site; it will redirect to `/setup` to create the first admin user.

The setup wizard creates Passport's personal access client (needed for API keys)
whenever the database doesn't have one yet, even if `storage/oauth-*.key` already
exist, e.g. when an existing install is pointed at a new SQL Server database. If a
database was set up some other way and creating an API key fails with
"Personal access client not found", run:

```bash
php artisan passport:client --personal --no-interaction
```

## Backups

Snipe-IT's built-in backups (Admin → Backups, `snipeit:backup`, the scheduled
backup, and the "backup before importing / purging" options) use
`spatie/db-dumper`, which has no SQL Server dumper. On SQL Server those backups
contain **files only** (uploads, OAuth keys and `.env`); `config/backup.php`
leaves the database out so they no longer fail.

Back the database up with SQL Server itself, e.g. a SQL Agent job or:

```sql
BACKUP DATABASE snipeit TO DISK = N'D:\Backups\snipeit.bak' WITH INIT, COMPRESSION;
```

`snipeit:restore` (and restoring from Admin → Backups) only supports MySQL /
MariaDB; restore the database with `RESTORE DATABASE` and the files from the zip.

## Notes / implementation details

- The `sqlsrv` connection sets `PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true`
  (see `config/database.php`) so integer/numeric columns return as native PHP
  numbers, matching the MySQL driver. Without this, `pdo_sqlsrv` returns them as
  strings, which breaks code that expects integers (e.g. Carbon's `addMonths()`
  under Carbon 3).
- A handful of migrations and the `Searchable` trait contain driver-specific
  branches (`CONCAT` quoting, `DATEADD` vs `DATE_ADD`, `TOP` vs `LIMIT`,
  `UPDATE ... FROM` joins, identity-column handling). MySQL/MariaDB behavior is
  unchanged.
- `insertOrIgnore()` is not supported by Laravel's SQL Server grammar; the
  affected migration and the bulk user merge use an existence check instead.

### Connection-level compatibility layer (`app/Services/SqlServer/`)

Some MySQL-isms are too common upstream to patch one call site at a time, so
the `sqlsrv` driver resolves to `App\Services\SqlServer\SqlServerConnection`
(registered in `AppServiceProvider::register()`), which handles them globally:

- **Date-times are MySQL-shaped.** Dates are written as `Y-m-d H:i:s` and the
  fractional seconds `pdo_sqlsrv` returns (`2026-10-01 09:30:00.000`) are
  trimmed on read. Snipe-IT validates raw values with
  `date_format:Y-m-d H:i:s` (e.g. `assets.last_checkout`, `last_audit_date`,
  DATETIME custom fields), so without this any asset that had been checked out
  or audited failed validation on its next save.
- **Repeated `ORDER BY` columns are dropped** (`SqlServerQueryGrammar`).
  MySQL ignores them; SQL Server rejects the query. Upstream chains them in
  relations such as `Asset::checkouts()`, which broke the asset detail page.
- **Large integer `whereIn()` lists are inlined** (`SqlServerQueryBuilder`),
  like Laravel's `whereIntegerInRaw()`. SQL Server allows at most 2100 bound
  parameters per statement; upstream passes plucked id lists (e.g. every
  soft-deleted record in a migration) that can exceed that. Multi-row
  inserts over the limit (e.g. adding license seats) are chunked in a
  transaction.
- **Unconvertible ids match nothing.** Comparing an integer `id` column with
  `'does-not-exist'` (or an id too large for the column) is a conversion error
  on SQL Server but simply no match on MySQL, which upstream relies on for
  404 / 422 responses and for `unique_undeleted` rules validated outside the
  model. `=` compiles to an always-false and `!=` to an always-true condition.
- **Explicit ids can be inserted.** Inserts that supply an `id` for an
  identity column run under `SET IDENTITY_INSERT`, as MySQL accepts them for
  `AUTO_INCREMENT` columns.
- **`limit()` applies to updates** (`update top (n)`), as MySQL's
  `UPDATE ... LIMIT` does. Laravel drops it, and a soft delete is an update:
  lowering a license's seat count soft-deleted every free seat.
- **Added nullable columns with a default fill existing rows**
  (`SqlServerSchemaGrammar` adds `WITH VALUES`), as MySQL does. Without it,
  rows that predate a column keep NULL, e.g. the seeded MAC Address custom
  field failed its `boolean` validation and could not be saved. Databases
  migrated before this fix still hold those NULLs.
- **Updates that set a NOT NULL column to NULL store `''` or `0`**
  (`SqlServerQueryBuilder::update()`), as MySQL does with `'strict' => false`,
  which upstream's `config/database.php` uses. Settings forms assign empty
  inputs and unchecked checkboxes as null, so without this the Security and
  Labels settings pages couldn't be saved. Inserts and date-time columns are
  unchanged: MySQL rejects those too.
- **Repeated select columns are dropped** (`SqlServerQueryGrammar`). Upstream
  occasionally selects a column twice (`locations.tag_color`, or a second
  `companies.*` when sorting companies by parent); SQL Server then rejects an
  `ORDER BY` on that name as ambiguous.
- **BIGINT columns are returned as ints and CHAR padding is stripped.**
  `pdo_sqlsrv` returns BIGINT as strings (upstream's newer tables and
  `foreignId()` columns compare them strictly), and pads fixed-width `char()`
  columns, which broke settings such as `digit_separator` (the EU number
  format never took effect), `skin` and the logo paths.

`withCount()` / `withSum()` aliases can't be referenced inside SQL Server
`ORDER BY` expressions or `HAVING` clauses. Code that does so (remaining-stock
sorts, low-inventory alerts, count-column search filters) goes through
`App\Models\Traits\InlinesAggregateAliases` / `App\Services\SqlServer\AggregateAliases`,
which inline the aggregate subquery on SQL Server and leave other drivers'
SQL unchanged.

### Merging upstream releases

See [Upgrading from upstream](upgrading-from-upstream.md) for the step-by-step
process, the list of files this fork changes, and the test tooling.
