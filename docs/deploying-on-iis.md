# Deploying on IIS (Windows Server, PHP 8.5, SQL Server)

How to run this fork on IIS with PHP 8.5 and SQL Server. See
[microsoft-sql-server.md](microsoft-sql-server.md) for what the SQL Server support
changes and [upgrading-from-upstream.md](upgrading-from-upstream.md) for pulling in
new Snipe-IT releases.

> The fork's test suite and browser checklist were last run on PHP 8.3. All locked
> Composer dependencies allow PHP 8.5, but run the suite on 8.5 before the first
> production deploy (see upgrading-from-upstream.md, "Test suite").

## 1. Server prerequisites

1. **IIS with CGI.** Server Manager → Add Roles and Features → Web Server (IIS) →
   Application Development → tick **CGI** (this installs FastCGI).
2. **IIS URL Rewrite 2.1** (Microsoft download). Without it every page but `/` 404s.
3. **Visual C++ Redistributable 2015–2022 (x64).**
4. **PHP 8.5, Non-Thread-Safe (NTS) x64 zip** from windows.php.net, extracted to
   `C:\PHP`. IIS runs PHP through FastCGI, which needs the NTS build.
5. **Microsoft Drivers for PHP for SQL Server 5.13.x** (5.13.0 is the first release
   that supports PHP 8.5; 5.12 stops at 8.3). From the
   [msphpsql releases](https://github.com/microsoft/msphpsql/releases), copy
   `php_sqlsrv_85_nts_x64.dll` and `php_pdo_sqlsrv_85_nts_x64.dll` into `C:\PHP\ext`.
6. **Microsoft ODBC Driver 18 for SQL Server.**
7. **Git** and **Composer**. Node is not needed: the built CSS/JS under
   `public/css/dist` and `public/js/dist` are committed.
8. Add `C:\PHP` to the system `PATH`.

## 2. php.ini

Copy `C:\PHP\php.ini-production` to `C:\PHP\php.ini` and set:

```ini
extension_dir = "ext"

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

Check with `php -m`: the list must include `sqlsrv`, `pdo_sqlsrv`, `sodium` and `zip`.
`php-cgi -m` shows what IIS will load.

## 3. Database

As a SQL Server administrator (SSMS or `sqlcmd`):

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

The instance needs mixed-mode authentication for a SQL login.

## 4. Code

Deploy outside OneDrive or any other synced folder:

```powershell
git clone https://github.com/kavickers/primary-it.git C:\inetpub\snipeit
cd C:\inetpub\snipeit
composer install --no-dev --optimize-autoloader --prefer-dist
copy .env.example .env
```

## 5. .env

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://snipeit.example.com
APP_TIMEZONE='America/New_York'

DB_CONNECTION=sqlsrv
DB_HOST=sql-server-host
DB_PORT=1433
DB_DATABASE=snipeit
DB_USERNAME=snipeit
DB_PASSWORD='a-strong-password'
DB_PREFIX=null
DB_ENCRYPT=yes
# true for a self-signed SQL Server certificate; false once it has a CA-signed one.
DB_TRUST_SERVER_CERTIFICATE=true
```

Fill in the `MAIL_*` settings too, then:

```powershell
php artisan key:generate --force
php artisan migrate --force
```

When moving an existing install, keep its `APP_KEY` instead of generating a new
one; encrypted fields can't be read with a different key.

## 6. IIS site

1. **Application pool** `snipeit`: .NET CLR version **No Managed Code**, pipeline
   mode **Integrated**.
2. **Site**: physical path **`C:\inetpub\snipeit\public`**, app pool `snipeit`,
   HTTPS binding with your certificate. Point it at `public`, never the repo root,
   or `.env` is downloadable.
3. **PHP handler**: Handler Mappings → Add Module Mapping: request path `*.php`,
   module `FastCgiModule`, executable `C:\PHP\php-cgi.exe`; under Request
   Restrictions → Verbs choose **All verbs**. Or from an elevated prompt:

   ```bat
   %windir%\system32\inetsrv\appcmd set config /section:system.webServer/fastCgi /+[fullPath='C:\PHP\php-cgi.exe',instanceMaxRequests='10000']
   %windir%\system32\inetsrv\appcmd set config /section:system.webServer/fastCgi /+[fullPath='C:\PHP\php-cgi.exe'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']
   %windir%\system32\inetsrv\appcmd set config /section:system.webServer/handlers /+[name='PHP_via_FastCGI',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='C:\PHP\php-cgi.exe',resourceType='Either']
   ```

4. **WebDAV**: remove the WebDAV module and handler from the site (or don't
   install the feature). Otherwise API `PUT`/`PATCH`/`DELETE` requests get 405.
5. **Default Document**: make sure `index.php` is listed.
6. **Rewrite rules** already ship in `public/web.config`.
7. **Uploads over 30 MB** (optional): add inside `<system.webServer>` in
   `public/web.config`:

   ```xml
   <security>
     <requestFiltering>
       <requestLimits maxAllowedContentLength="104857600" />
     </requestFiltering>
   </security>
   ```

## 7. Folder permissions

```powershell
icacls C:\inetpub\snipeit /grant "IIS AppPool\snipeit:(OI)(CI)RX" /T
icacls C:\inetpub\snipeit\storage /grant "IIS AppPool\snipeit:(OI)(CI)M" /T
icacls C:\inetpub\snipeit\bootstrap\cache /grant "IIS AppPool\snipeit:(OI)(CI)M" /T
icacls C:\inetpub\snipeit\public\uploads /grant "IIS AppPool\snipeit:(OI)(CI)M" /T
```

## 8. Cache and run setup

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Browse to `APP_URL`; it redirects to `/setup`, which creates the first admin and
Passport's personal access client (needed for API keys).

## 9. Scheduler

Alerts, cleanup and inventory jobs need `schedule:run` every minute. Create the
task with a service account that has Modify on `storage`:

```bat
schtasks /Create /TN "Snipe-IT scheduler" /SC MINUTE /MO 1 /RU "DOMAIN\svc-snipeit" /RP * ^
  /TR "C:\PHP\php.exe C:\inetpub\snipeit\artisan schedule:run"
```

## 10. Backups

On SQL Server, Snipe-IT's built-in backups contain files only (see
microsoft-sql-server.md, "Backups"). Back the database up with a SQL Agent job:

```sql
BACKUP DATABASE snipeit TO DISK = N'D:\Backups\snipeit.bak' WITH INIT, COMPRESSION;
```

## 11. Deploying updates

```powershell
cd C:\inetpub\snipeit
php artisan down
git pull origin master
composer install --no-dev --optimize-autoloader --prefer-dist
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

Then recycle the `snipeit` app pool so OPcache drops the old code. Run
`php artisan config:cache` again after any `.env` change.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page or 500 | Read `storage\logs\laravel.log`. If nothing is logged, recheck the folder permissions (step 7). |
| `could not find driver` | The sqlsrv DLLs don't match PHP: they must be 5.13+, `85`, `nts`, `x64`. Compare `php-cgi -m`. |
| 404 on every page except `/` | URL Rewrite is not installed. |
| 405 on API edits/deletes | WebDAV is still active, or the PHP handler isn't set to all verbs. |
| SSL/certificate error connecting to SQL Server | Set `DB_TRUST_SERVER_CERTIFICATE=true`, or give SQL Server a trusted certificate. |
| `.env` changes ignored | Run `php artisan config:cache` again. |
| "Personal access client not found" when creating an API key | `php artisan passport:client --personal --no-interaction` |
