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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('smm_order_id')->nullable()->constrained('smm_orders')->nullOnDelete();
            $table->string('type', 20)->default('credit'); // credit, debit
            $table->string('reference')->unique();        // our internal SMS_ reference
            $table->string('gateway')->default('lendoverify');
            $table->string('gateway_reference')->nullable(); // gateway's own ref
            $table->decimal('amount', 12, 2);             // NGN amount
            $table->string('currency', 5)->default('NGN');
            $table->string('status', 20)->default('pending'); // pending|paid|failed|refunded
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('gateway_response')->nullable();  // raw JSON from gateway
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
