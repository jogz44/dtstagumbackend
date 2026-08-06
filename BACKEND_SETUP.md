# Backend Setup Guide

This guide is for setting up the Laravel backend locally.

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
copy .env.example .env
```

Then generate the Laravel app key:

```powershell
php artisan key:generate
```

Update the database settings in `.env` to match your local database.

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

## Run Backend Server

```powershell
php artisan serve
```

By default, Laravel runs at:

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

### Composer says `openssl` is missing

Enable the `openssl` extension in your `php.ini` file, then retry Composer.

### Login fails for teammates

Make sure the teammate account was created through User Management after pulling the latest backend changes. Temporary users added before the backend fix may not exist in the database.

Also confirm the backend server is running and the frontend is calling the correct `/api` endpoint.