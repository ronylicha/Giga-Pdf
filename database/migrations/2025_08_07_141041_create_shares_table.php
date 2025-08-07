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
        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->foreignId('shared_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('shared_with')->nullable()->constrained('users')->onDelete('cascade');
            $table->enum('type', ['internal', 'public', 'protected']);
            $table->json('permissions'); // ['view', 'download', 'edit', 'comment']
            $table->string('token', 32)->nullable()->unique();
            $table->string('password')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('views_count')->default(0);
            $table->integer('downloads_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->string('last_accessed_ip')->nullable();
            $table->text('message')->nullable();
            $table->json('access_log')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('document_id');
            $table->index('shared_by');
            $table->index('shared_with');
            $table->index('token');
            $table->index('type');
            $table->index('expires_at');
            $table->index('is_active');
            
            // Optimisation MariaDB
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};