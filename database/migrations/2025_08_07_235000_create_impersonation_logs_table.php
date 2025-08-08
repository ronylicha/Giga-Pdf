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
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('impersonator_id');
            $table->unsignedBigInteger('impersonated_user_id');
            $table->enum('action', ['start', 'stop']);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('impersonator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('impersonated_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['impersonator_id', 'created_at']);
            $table->index(['impersonated_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('impersonation_logs');
    }
};