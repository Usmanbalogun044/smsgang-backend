<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('category', 40)->default('utility');
            $table->string('content_sid', 64)->nullable()->unique();
            $table->text('body_preview')->nullable();
            $table->json('variables_schema')->nullable();
            $table->decimal('unit_price_ngn', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('provider_status', 40)->nullable();
            $table->string('approval_status', 40)->nullable();
            $table->text('approval_reason')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'category'], 'whatsapp_templates_active_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
