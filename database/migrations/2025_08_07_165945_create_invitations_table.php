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
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('role')->default('user');
            $table->json('permissions')->nullable();
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'email']);
            $table->index('token');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};