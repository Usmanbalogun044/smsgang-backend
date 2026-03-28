<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('selected_operator')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('provider_price_usd', 12, 4)->nullable();
            $table->decimal('exchange_rate_used', 12, 4)->nullable();
            $table->decimal('effective_exchange_rate', 12, 4)->nullable();
            $table->string('global_markup_type_used', 20)->nullable();
            $table->decimal('global_markup_value_used', 12, 4)->nullable();
            $table->decimal('estimated_cost_ngn', 12, 2)->nullable();
            $table->decimal('profit_amount', 12, 2)->nullable();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('lendoverify_checkout_url')->nullable();
            $table->string('status')->default(OrderStatus::Pending->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
