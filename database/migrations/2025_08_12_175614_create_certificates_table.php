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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['self_signed', 'imported', 'ca_signed'])->default('self_signed');
            $table->integer('key_size')->default(2048); // 1024, 2048, 4096
            $table->string('common_name');
            $table->string('organization')->nullable();
            $table->string('organizational_unit')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('state')->nullable();
            $table->string('locality')->nullable();
            $table->string('email')->nullable();
            $table->text('certificate_path')->nullable(); // Encrypted path
            $table->text('private_key_path')->nullable(); // Encrypted path
            $table->text('password')->nullable(); // Encrypted password
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->string('serial_number')->nullable();
            $table->text('fingerprint')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'is_default']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};