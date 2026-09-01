<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;

class AutoArchiveCompletedDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:auto-archive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically archives documents that have been completed for at least 1 month and 1 week.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for completed documents eligible for auto-archiving...');
        $count = Document::autoArchiveCompleted();
        $this->info("Successfully auto-archived {$count} document(s).");

        return self::SUCCESS;
    }
}
