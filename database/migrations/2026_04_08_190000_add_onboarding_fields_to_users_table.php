<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_completed_onboarding')->default(false)->after('status');
            $table->timestamp('onboarding_completed_at')->nullable()->after('has_completed_onboarding');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['has_completed_onboarding', 'onboarding_completed_at']);
        });
    }
};
