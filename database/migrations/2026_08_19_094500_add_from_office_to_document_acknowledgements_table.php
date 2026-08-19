<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_acknowledgements', function (Blueprint $table) {
            $table->string('from_office')->nullable()->after('assigned_name');
        });
    }

    public function down(): void
    {
        Schema::table('document_acknowledgements', function (Blueprint $table) {
            $table->dropColumn('from_office');
        });
    }
};
