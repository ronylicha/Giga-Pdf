<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'extension')) {
                $table->string('extension', 10)->nullable()->after('mime_type');
                $table->index('extension');
            }
        });

        // Update existing records to set extension from original_name
        DB::table('documents')->whereNull('extension')->update([
            'extension' => DB::raw("LOWER(SUBSTRING_INDEX(original_name, '.', -1))"),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('extension');
        });
    }
};
