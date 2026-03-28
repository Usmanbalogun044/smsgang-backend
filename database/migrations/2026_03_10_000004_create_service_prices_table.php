<?php

use App\Enums\MarkupType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->decimal('provider_price', 10, 2)->default(0);
            $table->string('markup_type')->default(MarkupType::Fixed->value);
            $table->decimal('markup_value', 10, 2)->default(0);
            $table->decimal('final_price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
