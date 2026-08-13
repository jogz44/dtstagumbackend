# Backend Setup Guide

This guide is for setting up the Laravel backend locally and preparing for a future shared team MySQL database.

## Requirements

Install these first:

- PHP 8.2 or newer
- Composer
- A database supported by the project configuration

After installing PHP, confirm it works:

```powershell
php -v
```

After installing Composer, confirm it works:

```powershell
composer -V
```

## Quick Start

Use these commands from the project root after PHP, Composer, and MySQL are installed:

```powershell
cd backend
composer install
copy .Env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8000
```

Keep this terminal open while using the frontend. The backend API will be available at:

```text
http://127.0.0.1:8000
```

## Install Backend Dependencies

From the project root:

```powershell
cd backend
composer install
```

This installs Laravel and all backend packages.

## Environment File

If `.env` does not exist yet, create it from the example file:

```powershell
copy .Env.example .env
```

Then generate the Laravel app key:

```powershell
php artisan key:generate
```

Update the database settings in `.env` to match your environment. Real usernames, passwords, and server addresses belong only in local `.env` files. Do not commit `.env`.

For local development, use:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lgudts
DB_USERNAME=root
DB_PASSWORD=
```

This expects a local MySQL database named `lgudts` with the `root` user and no password. If your MySQL setup uses a password or a different database name, update these values.

For a future shared team MySQL environment, only after the team is ready to connect, use the shared server address:

```env
DB_CONNECTION=mysql
DB_HOST=<shared-mysql-server-ip>
DB_PORT=3306
DB_DATABASE=lgudts
DB_USERNAME=<application-user>
DB_PASSWORD=<application-password>
```

Do not use the MySQL `root` account as the application account for the shared database.

For shared-development runtime state, prefer local file-backed sessions and cache with synchronous queues:

```env
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

This avoids sharing developer sessions, cache entries, and queue jobs through the shared database. Do not change production configuration based only on this development rule.

## Database Setup

Run migrations:

```powershell
php artisan migrate
```

Seed default roles, permissions, and admin user:

```powershell
php artisan db:seed
```

Default seeded admin login:

```text
username: admin
password: 1234567
```

New teammate accounts created from User Management also use default password `1234567`.

## Shared Database Safety

These rules apply before anyone connects to a shared team MySQL database and while the team is using one:

- Only the designated migration owner runs migrations against the shared database.
- Never run `php artisan migrate:fresh` against the shared database.
- Never run `php artisan migrate:reset` against the shared database.
- Never run `php artisan db:wipe` against the shared database.
- Never commit `.env`.
- Do not use MySQL `root` as the application account.
- Back up the shared database before major schema changes.

When connected to the shared team database, do not run these commands casually:

```powershell
php artisan migrate:fresh
php artisan migrate:reset
php artisan db:wipe
```

These commands can affect everybody using the shared database.

The Composer `setup` script in `composer.json` currently runs:

```powershell
php artisan migrate --force
```

Do not run `composer setup` against the shared database unless you are the designated migration owner or have explicit approval from that owner.

Shared MySQL does not automatically share uploaded attachment files. The current attachment architecture is:

```text
shared database record
        +
local file on the uploader's computer
```

Attachment sharing will be handled in a later phase.

## Run Backend Server

From the `backend` folder, start Laravel with:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Keep this PowerShell window open. If you close it, the backend stops and login/API requests will fail.

Expected output:

```text
Server running on [http://127.0.0.1:8000]
```

To confirm the backend is reachable, open this in a browser:

```text
http://127.0.0.1:8000
```

## Laravel Boost

Laravel Boost is installed as a development dependency for AI/MCP tooling.

Teammates do not need to use Boost to log in or run the HR system. If they run normal backend setup with:

```powershell
composer install
```

Boost will be installed automatically because it is listed under `require-dev`.

If someone installs dependencies without dev packages:

```powershell
composer install --no-dev
```

The app can still run, but Boost commands will not be available.

Useful Boost commands:

```powershell
php artisan boost:list-skills
php artisan boost:update
php artisan boost:mcp
```

## Common Problems

### `php` is not recognized

PHP is not installed or is not added to PATH. Restart PowerShell after installing PHP, then try:

```powershell
php -v
```

### `Could not open input file: artisan`

You are not inside the backend folder. Run:

```powershell
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

### Composer says `openssl` is missing

Enable the `openssl` extension in your `php.ini` file, then retry Composer.

### Login fails for teammates

Make sure the teammate account was created through User Management after pulling the latest backend changes. Temporary users added before the backend fix may not exist in the database.

Also confirm the backend server is running and the frontend is calling the correct `/api` endpoint.

### Backend starts but database errors appear

Confirm MySQL is running, the database exists, and `.env` matches your local database settings. For this project, create/use a database named:

```text
lgudts
```

Then run:

```powershell
php artisan migrate
php artisan db:seed
```
