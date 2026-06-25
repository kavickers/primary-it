# Running Snipe-IT on Microsoft SQL Server

Upstream Snipe-IT targets MySQL/MariaDB. This fork also runs on **Microsoft SQL Server**
(tested against SQL Server 2025, and should work on 2016+). MySQL/MariaDB remain fully
supported — the SQL Server support is additive and selected via `DB_CONNECTION=sqlsrv`.

## Requirements

- **PHP 8.2+** with these extensions enabled (in addition to the normal Snipe-IT
  requirements):
  - `sqlsrv` and `pdo_sqlsrv` — the Microsoft Drivers for PHP for SQL Server
  - `sodium` and `zip` (required by Snipe-IT generally; make sure they are enabled)
- **Microsoft ODBC Driver 18 for SQL Server** installed on the host running PHP.
- A SQL Server instance with **mixed-mode authentication** if you intend to use a SQL login.

On Windows, download the matching thread-safe (TS) `php_sqlsrv`/`php_pdo_sqlsrv` DLLs for
your PHP version and add to `php.ini`:

```ini
extension=sodium
extension=zip
extension=sqlsrv
extension=pdo_sqlsrv
```

> If you change `php.ini`, restart your web server (Apache/Nginx/FPM). FastCGI/mod_php
> workers cache `php.ini` at startup, so a running server will not pick up newly enabled
> extensions until it is restarted.

## Database setup

Create a database and a login (run as an administrator, e.g. via `sqlcmd`):

```sql
CREATE DATABASE snipeit;
CREATE LOGIN snipeit WITH PASSWORD = 'a-strong-password';
USE snipeit;
CREATE USER snipeit FOR LOGIN snipeit;
ALTER ROLE db_owner ADD MEMBER snipeit;
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
  affected migration uses an existence check instead.
