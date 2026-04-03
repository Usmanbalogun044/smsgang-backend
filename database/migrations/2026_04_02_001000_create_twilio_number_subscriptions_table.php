<?php

use App\Enums\TwilioSubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twilio_number_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider')->default('twilio');
            $table->string('twilio_account_sid')->nullable();
            $table->string('twilio_number_sid')->unique();
            $table->string('phone_number_e164');
            $table->string('country_code', 8)->nullable();
            $table->json('capabilities')->nullable();

            $table->decimal('monthly_price_ngn', 12, 2);
            $table->decimal('provider_monthly_price_usd', 12, 4)->nullable();
            $table->decimal('exchange_rate_used', 12, 4)->nullable();
            $table->decimal('effective_exchange_rate', 12, 4)->nullable();
            $table->string('global_markup_type_used', 20)->nullable();
            $table->decimal('global_markup_value_used', 12, 4)->nullable();
            $table->decimal('twilio_markup_value_used', 12, 4)->nullable();
            $table->decimal('estimated_cost_ngn', 12, 2)->nullable();
            $table->decimal('profit_amount', 12, 2)->nullable();

            $table->boolean('auto_renew')->default(false);
            $table->string('status')->default(TwilioSubscriptionStatus::Pending->value);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('next_renewal_at')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'next_renewal_at']);
            $table->index(['expires_at']);
            $table->index(['phone_number_e164']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_number_subscriptions');
    }
};
