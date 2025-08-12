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
        if (! Schema::hasTable('subscription_history')) {
            Schema::create('subscription_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
                $table->string('old_plan')->nullable();
                $table->string('new_plan');
                $table->decimal('amount', 10, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->string('payment_method')->nullable();
                $table->string('transaction_id')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('expires_at')->nullable();
                $table->string('status')->default('active'); // active, expired, cancelled
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'created_at']);
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_history');
    }
};
