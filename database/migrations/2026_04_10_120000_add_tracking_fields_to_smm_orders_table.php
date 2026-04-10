<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smm_orders', function (Blueprint $table) {
            $table->boolean('tracking_auto_refill_enabled')->default(false)->after('status');
            $table->unsignedBigInteger('tracking_initial_count')->nullable()->after('tracking_auto_refill_enabled');
            $table->unsignedBigInteger('tracking_current_count')->nullable()->after('tracking_initial_count');
            $table->unsignedBigInteger('tracking_last_completed_quantity')->default(0)->after('tracking_current_count');
            $table->unsignedBigInteger('tracking_drop_detected_quantity')->default(0)->after('tracking_last_completed_quantity');
            $table->unsignedBigInteger('tracking_refilled_quantity')->default(0)->after('tracking_drop_detected_quantity');
            $table->unsignedBigInteger('tracking_outstanding_drop_quantity')->default(0)->after('tracking_refilled_quantity');
            $table->timestamp('tracking_last_drop_at')->nullable()->after('tracking_outstanding_drop_quantity');
            $table->timestamp('tracking_last_refill_at')->nullable()->after('tracking_last_drop_at');
            $table->timestamp('tracking_check_6h_at')->nullable()->after('tracking_last_refill_at');
            $table->timestamp('tracking_check_24h_at')->nullable()->after('tracking_check_6h_at');
            $table->timestamp('tracking_check_72h_at')->nullable()->after('tracking_check_24h_at');
            $table->timestamp('tracking_last_status_checked_at')->nullable()->after('tracking_check_72h_at');

            $table->index(['status', 'tracking_last_status_checked_at'], 'smm_orders_tracking_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('smm_orders', function (Blueprint $table) {
            $table->dropIndex('smm_orders_tracking_status_idx');

            $table->dropColumn([
                'tracking_auto_refill_enabled',
                'tracking_initial_count',
                'tracking_current_count',
                'tracking_last_completed_quantity',
                'tracking_drop_detected_quantity',
                'tracking_refilled_quantity',
                'tracking_outstanding_drop_quantity',
                'tracking_last_drop_at',
                'tracking_last_refill_at',
                'tracking_check_6h_at',
                'tracking_check_24h_at',
                'tracking_check_72h_at',
                'tracking_last_status_checked_at',
            ]);
        });
    }
};
