<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The preceding roles/permissions migration already creates UUID primary keys.
    }

    public function down(): void {}
};
