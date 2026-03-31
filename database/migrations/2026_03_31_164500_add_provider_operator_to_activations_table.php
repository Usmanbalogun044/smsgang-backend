<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activations', 'provider_operator')) {
            Schema::table('activations', function (Blueprint $table) {
                $table->string('provider_operator')->nullable()->after('provider');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activations', 'provider_operator')) {
            Schema::table('activations', function (Blueprint $table) {
                $table->dropColumn('provider_operator');
            });
        }
    }
};
