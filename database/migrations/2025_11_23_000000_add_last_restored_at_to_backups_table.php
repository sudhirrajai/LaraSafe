<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            if (!Schema::hasColumn('backups', 'last_restored_at')) {
                $table->timestamp('last_restored_at')->nullable()->after('last_backup_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            if (Schema::hasColumn('backups', 'last_restored_at')) {
                $table->dropColumn('last_restored_at');
            }
        });
    }
};
