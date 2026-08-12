<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class TestPmisConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pmis:test-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to the PMIS Microsoft SQL Server database and run initial harmless query';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('====================================================');
        $this->info(' Testing Microsoft SQL Server PMIS Connection');
        $this->info('====================================================');

        $host = config('database.connections.pmis.host');
        $port = config('database.connections.pmis.port');
        $database = config('database.connections.pmis.database');
        $username = config('database.connections.pmis.username');
        $password = config('database.connections.pmis.password');
        $driver = config('database.connections.pmis.driver');
        $encrypt = config('database.connections.pmis.encrypt');
        $trustCert = config('database.connections.pmis.trust_server_certificate');
        $timeout = config('database.connections.pmis.timeout');

        $this->line("Driver:                     {$driver}");
        $this->line("Target Host:                {$host}:{$port}");
        $this->line("Target Database:            {$database}");
        $this->line("Target Username:            {$username}");
        $this->line('Password Configured:        ' . (filled($password) ? 'yes' : 'no'));
        $this->line("Encrypt:                    " . (is_bool($encrypt) ? ($encrypt ? 'true' : 'false') : $encrypt));
        $this->line("Trust Server Certificate:  " . (is_bool($trustCert) ? ($trustCert ? 'true' : 'false') : $trustCert));
        $this->line("Timeout:                    {$timeout}s");
        $this->line('----------------------------------------------------');

        if (blank($password)) {
            $this->error(' Failed to connect to PMIS database.');
            $this->error('DB_PMIS_PASSWORD is empty in backend/.env.');

            $this->newLine();
            $this->comment('Set the SQL Server password for the hris login, then run:');
            $this->comment('php artisan optimize:clear');
            $this->comment('php artisan pmis:test-connection');

            return self::FAILURE;
        }

        try {
            $startTime = microtime(true);

            // Attempt connection
            $connection = DB::connection('pmis');
            $connection->getPdo();

            $connectDuration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info(" Connection established successfully ({$connectDuration} ms)!");

            // Run initial test query specified in prompt
            $this->line('Executing initial test query: SELECT GETDATE() AS DatabaseServerTime, DB_NAME() AS DatabaseName;');
            $result = $connection->select('SELECT GETDATE() AS DatabaseServerTime, DB_NAME() AS DatabaseName');

            if (!empty($result)) {
                $row = (array) $result[0];
                $serverTime = $row['DatabaseServerTime'] ?? $row['databaseservertime'] ?? 'N/A';
                $dbName = $row['DatabaseName'] ?? $row['databasename'] ?? 'N/A';

                $this->table(
                    ['DatabaseServerTime', 'DatabaseName'],
                    [
                        [$serverTime, $dbName]
                    ]
                );

                if (strcasecmp($dbName, 'pmis2003') === 0) {
                    $this->info(" Database Name Verification: Passed (Connected to '{$dbName}')");
                } else {
                    $this->warn(" Database Name Verification: Expected 'pmis2003', but got '{$dbName}'");
                }
            } else {
                $this->warn('Query executed successfully, but returned no rows.');
            }

            $this->info('====================================================');
            $this->info(' PMIS Database Connection Test Passed Successfully');
            $this->info('====================================================');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = $e->getMessage();

            $this->error(' Failed to connect to PMIS database.');
            $this->error('Error details: ' . $message);

            $this->newLine();
            $this->comment('Troubleshooting Tips:');
            if (str_contains($message, 'Encryption not supported on the client')) {
                $this->comment('1. The PMIS host is reachable, but the installed SQL Server client cannot complete the encryption negotiation.');
                $this->comment('2. Ask the SQL Server administrator whether Force Encryption is enabled on 10.0.1.35.');
                $this->comment('3. Install or repair the Microsoft ODBC Driver for SQL Server, then retry the command.');
            } else {
                $this->comment('1. Verify DB_PMIS_PASSWORD is set in your .env file.');
                $this->comment('2. Ensure the host 10.0.1.35 is reachable over port 1433.');
                $this->comment('3. Confirm SQL Server has remote connections enabled.');
            }

            return self::FAILURE;
        }
    }
}
