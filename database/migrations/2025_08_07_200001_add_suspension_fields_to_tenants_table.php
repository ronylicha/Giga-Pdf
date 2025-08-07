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
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'is_suspended')) {
                $table->boolean('is_suspended')->default(false)->after('subscription_expires_at');
            }
            if (!Schema::hasColumn('tenants', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('is_suspended');
            }
            if (!Schema::hasColumn('tenants', 'suspended_reason')) {
                $table->text('suspended_reason')->nullable()->after('suspended_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['is_suspended', 'suspended_at', 'suspended_reason']);
        });
    }
};