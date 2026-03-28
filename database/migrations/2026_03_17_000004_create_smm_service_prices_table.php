<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smm_service_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('smm_service_id');
            $table->string('markup_type');
            $table->decimal('markup_value', 12, 4);
            $table->decimal('final_price', 12, 2);
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('smm_service_id')->references('id')->on('smm_services')->onDelete('cascade');
            $table->unique('smm_service_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_service_prices');
    }
};
