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
        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('from_format', 20);
            $table->string('to_format', 20);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
            $table->integer('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('result_document_id')->nullable()->constrained('documents');
            $table->integer('retry_count')->default(0);
            $table->string('queue_id')->nullable();
            $table->integer('processing_time')->nullable(); // en secondes
            $table->timestamps();
            
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['document_id', 'status']);
            $table->index('created_at');
            $table->index('queue_id');
            
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
        Schema::dropIfExists('conversions');
    }
};