<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smm_orders', function (Blueprint $table) {
            // Make crestpanel_order_id nullable (orders are created before CrestPanel response)
            $table->string('crestpanel_order_id')->nullable()->change();
            
            // Remove unique constraint on crestpanel_order_id since it can be null during pending state
            $table->dropUnique(['crestpanel_order_id']);
        });

        Schema::table('smm_orders', function (Blueprint $table) {
            // Update status enum to include new states
            $table->enum('status', [
                'pending_provider_confirmation',  // Order created locally, awaiting CrestPanel response
                'Pending',                        // CrestPanel accepted, awaiting processing
                'In progress',                    // Being processed by provider
                'Partial',                        // Partial completion
                'Completed',                      // Successfully completed
                'Failed',                         // Completed but with errors
                'failed_at_provider',             // CrestPanel call failed, but order is recorded locally with funds deducted
                'Cancelled'                       // User cancelled
            ])->default('pending_provider_confirmation')->change();
        });
    }

    public function down(): void
    {
        Schema::table('smm_orders', function (Blueprint $table) {
            // Revert to original status enum
            $table->enum('status', [
                'Pending',
                'In progress',
                'Partial',
                'Completed',
                'Failed',
                'Cancelled'
            ])->default('Pending')->change();
            
            // Re-add unique constraint (this will fail if any null values exist)
            $table->unique('crestpanel_order_id');
            
            // Make crestpanel_order_id not nullable
            $table->string('crestpanel_order_id')->nullable(false)->change();
        });
    }
};
