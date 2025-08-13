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
        Schema::table('documents', function (Blueprint $table) {
            // Make stored_name and hash nullable to allow creation without immediate file storage
            $table->string('stored_name')->nullable()->change();
            $table->string('hash', 64)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Revert to non-nullable (this might fail if there are null values)
            $table->string('stored_name')->nullable(false)->change();
            $table->string('hash', 64)->nullable(false)->change();
        });
    }
};