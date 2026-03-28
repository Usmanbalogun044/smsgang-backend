<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smm_services', function (Blueprint $table) {
            $table->id();
            $table->string('crestpanel_service_id')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('type')->nullable();
            $table->decimal('rate', 12, 4);
            $table->bigInteger('min');
            $table->bigInteger('max');
            $table->boolean('refill')->default(false);
            $table->boolean('cancel')->default(false);
            $table->json('provider_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_services');
    }
};
