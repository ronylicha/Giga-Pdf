<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('hash', 64);
            $table->json('metadata')->nullable();
            $table->boolean('is_public')->default(false);
            $table->foreignId('parent_id')->nullable()->constrained('documents');
            $table->text('search_content')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->integer('page_count')->nullable();
            $table->string('status')->default('active');
            $table->json('tags')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->integer('access_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('mime_type');
            $table->index('hash');
            $table->index('parent_id');
            $table->index('status');
            $table->index(['tenant_id', 'is_public']);
            
            // Optimisation MariaDB
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
        
        // Ajouter l'index full-text après la création de la table
        DB::statement('ALTER TABLE documents ADD FULLTEXT ft_search (original_name, search_content)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};