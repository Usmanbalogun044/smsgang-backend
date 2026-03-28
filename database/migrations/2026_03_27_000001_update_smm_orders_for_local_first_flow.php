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

            // Keep domain states in PHP enums; use string column in DB.
            $table->string('status', 40)->default('pending_provider_confirmation')->change();
        });
    }

    public function down(): void
    {
        Schema::table('smm_orders', function (Blueprint $table) {
            $table->string('status', 40)->default('Pending')->change();
            
            // Re-add unique constraint (this will fail if any null values exist)
            $table->unique('crestpanel_order_id');
            
            // Make crestpanel_order_id not nullable
            $table->string('crestpanel_order_id')->nullable(false)->change();
        });
    }
};
