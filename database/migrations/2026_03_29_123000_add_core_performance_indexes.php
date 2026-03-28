<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
            $table->index(['status', 'created_at'], 'orders_status_created_idx');
            $table->index(['service_id', 'country_id', 'status'], 'orders_service_country_status_idx');
        });

        Schema::table('activations', function (Blueprint $table) {
            $table->index(['order_id', 'status'], 'activations_order_status_idx');
            $table->index(['status', 'expires_at'], 'activations_status_expires_idx');
            $table->index(['provider_activation_id'], 'activations_provider_activation_idx');
            $table->index(['created_at'], 'activations_created_idx');
        });

        Schema::table('service_prices', function (Blueprint $table) {
            $table->index(['service_id', 'is_active', 'available_count'], 'service_prices_service_active_available_idx');
            $table->index(['country_id', 'is_active'], 'service_prices_country_active_idx');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->index(['is_active', 'name'], 'services_active_name_idx');
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->index(['is_active', 'name'], 'countries_active_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_created_idx');
            $table->dropIndex('orders_status_created_idx');
            $table->dropIndex('orders_service_country_status_idx');
        });

        Schema::table('activations', function (Blueprint $table) {
            $table->dropIndex('activations_order_status_idx');
            $table->dropIndex('activations_status_expires_idx');
            $table->dropIndex('activations_provider_activation_idx');
            $table->dropIndex('activations_created_idx');
        });

        Schema::table('service_prices', function (Blueprint $table) {
            $table->dropIndex('service_prices_service_active_available_idx');
            $table->dropIndex('service_prices_country_active_idx');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex('services_active_name_idx');
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropIndex('countries_active_name_idx');
        });
    }
};
