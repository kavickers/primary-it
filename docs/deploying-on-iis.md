# Deploying on IIS (Windows Server, PHP 8.5, SQL Server)

How to run this fork on IIS with PHP 8.5 and SQL Server, on a server that may
also host other PHP versions. See [microsoft-sql-server.md](microsoft-sql-server.md)
for what the SQL Server support changes and
[upgrading-from-upstream.md](upgrading-from-upstream.md) for pulling in new
Snipe-IT releases.

The examples use this layout; adjust to yours:

| | |
|---|---|
| PHP 8.5 | `C:\Program Files\php8.5` |
| Site folder | `E:\SnipeIT` (IIS physical path `E:\SnipeIT\public`) |
| IIS site / app pool | `SnipeIT` |
| URL | `http://snipeit.example.com` |

> The fork's test suite and browser checklist were last run on PHP 8.3. All locked
> Composer dependencies allow PHP 8.5, but run the suite on 8.5 before relying on
> it (see upgrading-from-upstream.md, "Test suite").

Run the commands below in Command Prompt (`cmd.exe`), elevated where noted. Use
`cd /d E:\SnipeIT` to change drive and folder in one step.

## 1. Server prerequisites

1. **IIS with CGI.** Server Manager → Add Roles and Features → Web Server (IIS) →
   Application Development → tick **CGI** (this installs FastCGI).
2. **IIS URL Rewrite 2.1 (x64).** Without it IIS can't read `public/web.config`
   (HTTP 500.19, error `0x8007000d`). Installing it doesn't affect sites without
   rewrite rules, but the installer may restart IIS.
3. **Visual C++ Redistributable 2015–2022 (x64).**
4. **PHP 8.5, Non-Thread-Safe (NTS) x64 zip** from windows.php.net, extracted to
   `C:\Program Files\php8.5`. IIS runs PHP through FastCGI, which needs the NTS build.
5. **Microsoft Drivers for PHP for SQL Server 5.13.x** (5.13.0 is the first release
   that supports PHP 8.5; 5.12 stops at 8.3). From the
   [msphpsql releases](https://github.com/microsoft/msphpsql/releases), copy
   `php_sqlsrv_85_nts_x64.dll` and `php_pdo_sqlsrv_85_nts_x64.dll` into
   `C:\Program Files\php8.5\ext`.
6. **Microsoft ODBC Driver 18 for SQL Server.**
7. **Git for Windows** and **Composer** (Composer-Setup.exe; point it at
   `C:\Program Files\php8.5\php.exe`). Node is not needed: the built CSS/JS under
   `public/css/dist` and `public/js/dist` are committed.

### Running next to another PHP version

If the system `PATH` points at another PHP (e.g. 7.1 for an older site), leave it:
IIS sites use the `php-cgi.exe` named in their handler mapping, not `PATH`. Call
PHP 8.5 by its full path for Snipe-IT instead. To shorten Composer, save this as
`C:\Program Files\php8.5\composer85.bat`:

```bat
@"C:\Program Files\php8.5\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" %*
```

Composer runs its post-install `artisan` scripts with the PHP that started it, so
this installs everything on 8.5.

If `set PHPRC` shows a value, every PHP reads that folder's `php.ini`. Add
`-c "C:\Program Files\php8.5\php.ini"` after `php.exe` in the commands here (and in
`composer85.bat`), and give IIS the right one in step 6.

## 2. php.ini

Copy `php.ini-production` to `php.ini` in `C:\Program Files\php8.5` and set:

```ini
; Absolute: a relative "ext" resolves against the current folder, and an unset
; extension_dir makes PHP look in C:\php\ext.
extension_dir = "C:\Program Files\php8.5\ext"

extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=ldap
extension=mbstring
extension=exif
extension=openssl
extension=sodium
extension=zip
extension=php_sqlsrv_85_nts_x64.dll
extension=php_pdo_sqlsrv_85_nts_x64.dll

; OPcache is built into PHP 8.5; don't add a zend_extension=opcache line.
opcache.enable=1

cgi.fix_pathinfo=1
cgi.force_redirect=0
fastcgi.impersonate=1

memory_limit=512M
upload_max_filesize=100M
post_max_size=100M
max_execution_time=300
date.timezone=America/New_York
```

Check:

```bat
"C:\Program Files\php8.5\php.exe" --ini
"C:\Program Files\php8.5\php.exe" -m
```

`--ini` must show `C:\Program Files\php8.5\php.ini` as the loaded file, and `-m`
must list `curl`, `openssl`, `sodium`, `zip`, `sqlsrv` and `pdo_sqlsrv` without
startup warnings.

## 3. Database

SQL Server needs **mixed-mode authentication** (server Properties → Security →
SQL Server and Windows Authentication mode; restart the service) and **TCP/IP
enabled** (SQL Server Configuration Manager → Protocols → TCP/IP; off by default on
Express and Developer).

Create the database, then the login and database user. You need sysadmin, or
securityadmin plus dbcreator.

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

The `GO`s matter: without them `USE snipeit` fails because the database doesn't
exist yet when the batch is compiled.

Or in SSMS:

1. Security → Logins → New Login: name `snipeit`, **SQL Server authentication**,
   untick "Enforce password expiration" and "User must change password at next
   login", default database `snipeit`.
2. Databases → snipeit → Security → Users → New User: type **SQL user with login**,
   user name and login name both `snipeit`, default schema `dbo`; on Membership tick
   **db_owner**. (Or on the login's **User Mapping** page, tick `snipeit` and
   `db_owner`.)

## 4. Code

Deploy outside OneDrive or any other synced folder. `git clone` needs an empty
folder; if `E:\SnipeIT` already exists, rename it and clone fresh:

```bat
cd /d E:\
git clone https://github.com/kavickers/primary-it.git SnipeIT
cd /d E:\SnipeIT
"C:\Program Files\php8.5\composer85" install --no-dev --optimize-autoloader --prefer-dist
copy .env.example .env
```

If `composer install` stops at `package:discover` with "bootstrap\cache directory
must be present and writable", do the permissions in step 7 first, then run
`"C:\Program Files\php8.5\composer85" dump-autoload --optimize`.

When replacing an older Snipe-IT install, copy its `.env` (keep `APP_KEY`),
`public\uploads\`, `storage\private_uploads\` and `storage\oauth-*.key` into the new
folder.

## 5. .env

`.env.example` already defaults to SQL Server. Set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://snipeit.example.com
APP_TIMEZONE='America/New_York'

DB_CONNECTION=sqlsrv
DB_HOST=your-sql-server
DB_PORT=1433
DB_DATABASE=snipeit
DB_USERNAME=snipeit
DB_PASSWORD='a-strong-password'
DB_PREFIX=null
DB_ENCRYPT=yes
# true for a self-signed SQL Server certificate; false once it has a CA-signed one.
DB_TRUST_SERVER_CERTIFICATE=true
```

- `APP_URL` must match what users type, without a trailing slash.
- `DB_HOST` can be the machine name, `localhost` or an IP. For a named instance use
  the server name and set `DB_PORT` to the instance's TCP port.
- Leave `DB_PREFIX=null` (no table-name prefix); don't change it after installing.
- Over HTTPS, also set `SECURE_COOKIES=true` and `APP_FORCE_TLS=true`. Keep
  `SECURE_COOKIES=false` on plain HTTP or logins won't stick.

Fill in the `MAIL_*` settings too, then:

```bat
"C:\Program Files\php8.5\php.exe" artisan key:generate --force
"C:\Program Files\php8.5\php.exe" artisan migrate --force
```

When moving an existing install, keep its `APP_KEY` instead of generating a new
one; encrypted fields can't be read with a different key.

## 6. IIS site

1. **FastCGI application** (server level, elevated prompt). `web.config` can't
   register this itself:

   ```bat
   %windir%\system32\inetsrv\appcmd set config /section:system.webServer/fastCgi /+[fullPath='C:\Program Files\php8.5\php-cgi.exe',instanceMaxRequests='10000',activityTimeout='300',requestTimeout='300']
   %windir%\system32\inetsrv\appcmd set config /section:system.webServer/fastCgi /+[fullPath='C:\Program Files\php8.5\php-cgi.exe'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']
   ```

   If `PHPRC` is set system-wide, also add
   `/+[fullPath='C:\Program Files\php8.5\php-cgi.exe'].environmentVariables.[name='PHPRC',value='C:\Program Files\php8.5']`
   (or IIS Manager → server → FastCGI Settings → the 8.5 entry → Environment
   Variables). This only affects Snipe-IT.
2. **Application pool** `SnipeIT`: .NET CLR version **No Managed Code**, pipeline
   mode **Integrated**, identity **ApplicationPoolIdentity**.
3. **Site** `SnipeIT`: physical path **`E:\SnipeIT\public`**, app pool `SnipeIT`.
   Point it at `public`, never the repo root, or `.env` is downloadable.
4. **Anonymous Authentication**: site → Authentication → Anonymous Authentication →
   Edit → **Application pool identity**. With `fastcgi.impersonate=1`, PHP otherwise
   runs as `IUSR`, and Snipe-IT reports `storage` as not writable even after step 7.
5. **Binding**: http, port 80, host name `snipeit.example.com`, plus a DNS A
   record for that name pointing at the server. For HTTPS add an https/443 binding
   with the same host name and a certificate.

Then, with **Sites → SnipeIT** selected (not the server node), so IIS writes the
settings into `E:\SnipeIT\public\web.config` next to the rewrite rules that ship
there:

1. **Handler Mappings**: remove the other PHP version's mapping (e.g. `PHP7`).
   Open the PHP 8.5 mapping, check the executable is
   `C:\Program Files\php8.5\php-cgi.exe`, and under Request Restrictions → Verbs
   choose **All verbs**. Remove **WebDAV** if it's listed.
2. **Modules**: remove **WebDAVModule** if listed. WebDAV otherwise answers API
   `PUT`/`PATCH`/`DELETE` with 405.
3. **Default Document**: add `index.php` and move it to the top.
4. **Request Filtering** → Edit Feature Settings → Maximum allowed content length:
   `104857600` (100 MB, matching `php.ini`).
5. **Configuration Editor** → `system.webServer/httpErrors` → `existingResponse` =
   `PassThrough` → Apply, so Laravel's own error responses (API validation errors
   are JSON) reach the client.
6. **URL Rewrite** should list "Imported Rule 1" and "Imported Rule 2". If not,
   `public/web.config` lost them: `git checkout -- public/web.config` and redo 1–5.

Because these edits land in a tracked file, tell git to leave the server's copy
alone so `git pull` doesn't refuse:

```bat
cd /d E:\SnipeIT
git update-index --skip-worktree public/web.config
```

If upstream ever changes `public/web.config`, a pull will then stop on it: run
`git update-index --no-skip-worktree public/web.config`, copy the file aside,
`git checkout -- public/web.config`, pull, and redo steps 1–5.

## 7. Folder permissions

Elevated prompt. Find the pool's account under Application Pools → SnipeIT →
Advanced Settings → Identity: for ApplicationPoolIdentity it's
`IIS AppPool\SnipeIT`.

```bat
icacls E:\SnipeIT /grant "IIS AppPool\SnipeIT:(OI)(CI)RX"
icacls E:\SnipeIT\storage /grant "IIS AppPool\SnipeIT:(OI)(CI)M" /T
icacls E:\SnipeIT\bootstrap\cache /grant "IIS AppPool\SnipeIT:(OI)(CI)M" /T
icacls E:\SnipeIT\public\uploads /grant "IIS AppPool\SnipeIT:(OI)(CI)M" /T
```

Your own account needs Modify on `storage` and `bootstrap\cache` too, to run
Composer and artisan. If `icacls` says "Access is denied" even elevated, take
ownership first:

```bat
takeown /F E:\SnipeIT /R /D Y
icacls E:\SnipeIT /grant Administrators:(OI)(CI)F /T
```

## 8. Cache and run setup

```bat
cd /d E:\SnipeIT
"C:\Program Files\php8.5\php.exe" artisan config:cache
"C:\Program Files\php8.5\php.exe" artisan route:cache
"C:\Program Files\php8.5\php.exe" artisan view:cache
```

Browse to `APP_URL`; it redirects to `/setup`, which creates the first admin and
Passport's personal access client (needed for API keys).

## 9. Scheduler

Alerts, cleanup and inventory jobs need `schedule:run` every minute. Create the
task with a service account that has Modify on `storage`:

```bat
schtasks /Create /TN "Snipe-IT scheduler" /SC MINUTE /MO 1 /RU "DOMAIN\svc-snipeit" /RP * /TR "\"C:\Program Files\php8.5\php.exe\" E:\SnipeIT\artisan schedule:run"
```

## 10. Backups

On SQL Server, Snipe-IT's built-in backups contain files only (see
microsoft-sql-server.md, "Backups"). Back the database up with a SQL Agent job:

```sql
BACKUP DATABASE snipeit TO DISK = N'D:\Backups\snipeit.bak' WITH INIT, COMPRESSION;
```

## 11. Deploying updates

```bat
cd /d E:\SnipeIT
"C:\Program Files\php8.5\php.exe" artisan down
git pull origin master
"C:\Program Files\php8.5\composer85" install --no-dev --optimize-autoloader --prefer-dist
"C:\Program Files\php8.5\php.exe" artisan migrate --force
"C:\Program Files\php8.5\php.exe" artisan optimize:clear
"C:\Program Files\php8.5\php.exe" artisan config:cache
"C:\Program Files\php8.5\php.exe" artisan route:cache
"C:\Program Files\php8.5\php.exe" artisan view:cache
"C:\Program Files\php8.5\php.exe" artisan up
```

Then recycle the `SnipeIT` app pool so OPcache drops the old code. Run
`artisan config:cache` again after any `.env` change.

If `git pull` refuses because a tracked file was edited on the server, discard
the local edit with `git checkout -- <file>` (or see step 6 for
`public/web.config`) and pull again.

## Troubleshooting

| Symptom | Fix |
|---|---|
| HTTP 500.19, error `0x8007000d` | URL Rewrite isn't installed, or `public/web.config` isn't valid XML (`git checkout -- public/web.config`). |
| HTTP 500.19, error `0x80070021` | A `web.config` section is locked at server level; unlock it (IIS Manager → server → Configuration Editor → the section → Unlock Section). |
| "storage directory ... not writable by the web-server" | Anonymous Authentication still uses `IUSR` (step 6.4), or the pool account lacks Modify on `storage` (step 7). Recycle the app pool after fixing. |
| Blank page or 500 | Read `storage\logs\laravel.log`. If nothing is logged, recheck the folder permissions (step 7). |
| `Unable to load dynamic library ... C:\php\ext` | PHP isn't reading `C:\Program Files\php8.5\php.ini`, or `extension_dir` isn't absolute (step 2). Check `php.exe --ini` and `PHPRC`. |
| `could not find driver` | The sqlsrv DLLs don't match PHP: they must be 5.13+, `85`, `nts`, `x64`. Compare `php-cgi.exe -m`. |
| Composer or artisan errors about the PHP version | Plain `php` is the other PHP version; use the full PHP 8.5 path (step 1). |
| 404 on every page except `/` | URL Rewrite is not installed. |
| 405 on API edits/deletes | WebDAV is still active at server level, or the PHP handler isn't set to all verbs. |
| Login with SQL user fails (18456) | Mixed-mode authentication is off (step 3). |
| SSL/certificate error connecting to SQL Server | Set `DB_TRUST_SERVER_CERTIFICATE=true`, or give SQL Server a trusted certificate. |
| Logging in just reloads the login page | `SECURE_COOKIES=true` on plain HTTP; set it to `false` or use HTTPS. |
| `.env` changes ignored | Run `artisan config:cache` again. |
| "Personal access client not found" when creating an API key | `"C:\Program Files\php8.5\php.exe" artisan passport:client --personal --no-interaction` |
