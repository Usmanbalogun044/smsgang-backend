<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_virtual_service_markups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('markup_type', 20)->default('fixed');
            $table->decimal('markup_value', 12, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'service_id', 'country_id'], 'vendor_virtual_unique');
            $table->index(['user_id', 'service_id', 'is_active'], 'vendor_virtual_lookup_idx');
        });

        Schema::create('vendor_smm_service_markups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('smm_service_id')->constrained('smm_services')->cascadeOnDelete();
            $table->string('markup_type', 20)->default('fixed');
            $table->decimal('markup_value', 12, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'smm_service_id'], 'vendor_smm_unique');
            $table->index(['user_id', 'smm_service_id', 'is_active'], 'vendor_smm_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_smm_service_markups');
        Schema::dropIfExists('vendor_virtual_service_markups');
    }
};
