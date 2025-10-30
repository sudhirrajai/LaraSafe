<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('cloud_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->unique(); // s3, b2, wasabi
            $table->json('config'); // Store the settings as JSON
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cloud_settings');
    }
};
