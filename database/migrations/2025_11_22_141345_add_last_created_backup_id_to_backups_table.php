<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            // Add the missing column
            $table->uuid('last_created_backup_id')->nullable()->after('auto_delete_after_days');
            
            // Add foreign key constraint if needed
            $table->foreign('last_created_backup_id')
                  ->references('id')
                  ->on('created_backups')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['last_created_backup_id']);
            $table->dropColumn('last_created_backup_id');
        });
    }
};