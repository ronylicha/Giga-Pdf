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
        Schema::table('tenants', function (Blueprint $table) {
            // Le slug doit rester unique, mais pas le nom
            // On ne touche pas à la contrainte d'unicité du slug
            // Pas de contrainte d'unicité sur le nom dans la migration originale
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Rien à faire car il n'y avait pas de contrainte d'unicité sur le nom
        });
    }
};
