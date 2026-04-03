<?php

use App\Enums\TwilioMessageDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twilio_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('twilio_number_subscription_id')->nullable()->constrained('twilio_number_subscriptions')->nullOnDelete();

            $table->string('message_sid')->unique();
            $table->string('direction')->default(TwilioMessageDirection::Outbound->value);
            $table->string('status')->nullable();
            $table->string('from_number', 32)->nullable();
            $table->string('to_number', 32)->nullable();
            $table->text('body')->nullable();
            $table->unsignedInteger('segments')->default(1);

            $table->decimal('provider_cost_usd', 12, 6)->nullable();
            $table->decimal('charged_amount_ngn', 12, 2)->nullable();
            $table->string('currency', 8)->default('USD');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index(['twilio_number_subscription_id', 'created_at']);
            $table->index(['direction', 'status']);
            $table->index(['from_number']);
            $table->index(['to_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_messages');
    }
};
