$php = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'

if (-not (Test-Path $php)) {
    Write-Error "PHP was not found at $php. Install PHP 8.2+ or update this script with your php.exe path."
    exit 1
}

& $php artisan serve --host=127.0.0.1 --port=8000