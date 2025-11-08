<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('auto_delete_enabled')->default(false)->after('include_database');
            $table->integer('auto_delete_after_days')->nullable()->after('auto_delete_enabled')
                  ->comment('Number of days after which backups should be auto-deleted');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['auto_delete_enabled', 'auto_delete_after_days']);
        });
    }
};