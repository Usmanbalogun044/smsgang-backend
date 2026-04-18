<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->string('message_sid', 64)->unique();
            $table->string('direction', 20)->default('outbound');
            $table->string('status', 40)->default('queued');
            $table->string('from_number', 40)->nullable();
            $table->string('to_number', 40)->nullable();
            $table->json('template_variables')->nullable();
            $table->decimal('unit_price_ngn', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('charged_amount_ngn', 12, 2)->default(0);
            $table->decimal('provider_cost_value', 14, 6)->nullable();
            $table->string('provider_cost_currency', 12)->nullable();
            $table->decimal('provider_cost_ngn_estimate', 12, 2)->nullable();
            $table->decimal('fx_rate_used', 12, 4)->nullable();
            $table->decimal('profit_amount_ngn', 12, 2)->default(0);
            $table->string('billing_status', 20)->default('not_charged');
            $table->string('billing_reference', 120)->nullable()->unique();
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'whatsapp_messages_user_created_idx');
            $table->index(['status', 'created_at'], 'whatsapp_messages_status_created_idx');
            $table->index(['to_number', 'created_at'], 'whatsapp_messages_to_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
