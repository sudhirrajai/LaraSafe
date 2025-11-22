<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            if (!Schema::hasColumn('backups', 'error_message')) {
                $table->text('error_message')->nullable();
            }
            
            if (!Schema::hasColumn('backups', 'last_created_backup_id')) {
                $table->uuid('last_created_backup_id')->nullable();
                
                $table->foreign('last_created_backup_id')
                      ->references('id')
                      ->on('created_backups')
                      ->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            if (Schema::hasColumn('backups', 'last_created_backup_id')) {
                $table->dropForeign(['last_created_backup_id']);
                $table->dropColumn('last_created_backup_id');
            }
            
            if (Schema::hasColumn('backups', 'error_message')) {
                $table->dropColumn('error_message');
            }
        });
    }
};