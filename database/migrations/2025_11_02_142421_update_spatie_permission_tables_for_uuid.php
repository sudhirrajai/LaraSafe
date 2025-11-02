<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropIndex(['model_id', 'model_type']); // Drop old index
            $table->string('model_id', 36)->change(); // Change from int → string/uuid
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
        });

        // ✅ Update model_has_roles
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropIndex(['model_id', 'model_type']); // Drop old index
            $table->string('model_id', 36)->change(); // Change from int → string/uuid
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
        });
    }

    public function down(): void
    {
        // Revert to integer (if needed)
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropIndex(['model_id', 'model_type']);
            $table->unsignedBigInteger('model_id')->change();
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropIndex(['model_id', 'model_type']);
            $table->unsignedBigInteger('model_id')->change();
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
        });
    }
};
