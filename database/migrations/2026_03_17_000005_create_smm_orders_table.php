<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smm_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('smm_service_id');
            $table->string('crestpanel_order_id')->unique();
            $table->text('link');
            $table->bigInteger('quantity');
            $table->bigInteger('runs')->nullable();
            $table->bigInteger('interval')->nullable();
            $table->text('comments')->nullable();
            $table->decimal('price_per_unit', 12, 2);
            $table->bigInteger('total_units');
            $table->decimal('total_cost_ngn', 12, 2);
            $table->decimal('charge_ngn', 12, 2)->nullable();
            $table->decimal('exchange_rate_used', 8, 4);
            $table->string('markup_type_used');
            $table->decimal('markup_value_used', 12, 4);
            $table->json('provider_payload')->nullable();
            $table->enum('status', ['Pending', 'In progress', 'Partial', 'Completed', 'Failed', 'Cancelled'])->default('Pending');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('smm_service_id')->references('id')->on('smm_services')->onDelete('restrict');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_orders');
    }
};
