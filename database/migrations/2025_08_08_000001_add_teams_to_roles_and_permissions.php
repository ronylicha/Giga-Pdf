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
        // Add team_id to roles table
        if (!Schema::hasColumn('roles', 'team_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->after('guard_name');
                $table->index('team_id');
            });
        }
        
        // Add team_id to permissions table
        if (!Schema::hasColumn('permissions', 'team_id')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable()->after('guard_name');
                $table->index('team_id');
            });
        }
        
        // Add team_id to model_has_permissions table
        if (!Schema::hasColumn('model_has_permissions', 'team_id')) {
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable();
                $table->index('team_id');
            });
        }
        
        // Add team_id to model_has_roles table
        if (!Schema::hasColumn('model_has_roles', 'team_id')) {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('team_id')->nullable();
                $table->index('team_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove team_id from model_has_permissions table
        if (Schema::hasColumn('model_has_permissions', 'team_id')) {
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->dropColumn('team_id');
            });
        }
        
        // Remove team_id from model_has_roles table
        if (Schema::hasColumn('model_has_roles', 'team_id')) {
            Schema::table('model_has_roles', function (Blueprint $table) {
                $table->dropColumn('team_id');
            });
        }
        
        // Remove team_id from roles table
        if (Schema::hasColumn('roles', 'team_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('team_id');
            });
        }
        
        // Remove team_id from permissions table  
        if (Schema::hasColumn('permissions', 'team_id')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropColumn('team_id');
            });
        }
    }
};