<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('vendor_virtual_markup_type', 20)->nullable()->after('status');
            $table->decimal('vendor_virtual_markup_value', 12, 4)->nullable()->after('vendor_virtual_markup_type');
            $table->string('vendor_smm_markup_type', 20)->nullable()->after('vendor_virtual_markup_value');
            $table->decimal('vendor_smm_markup_value', 12, 4)->nullable()->after('vendor_smm_markup_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_virtual_markup_type',
                'vendor_virtual_markup_value',
                'vendor_smm_markup_type',
                'vendor_smm_markup_value',
            ]);
        });
    }
};