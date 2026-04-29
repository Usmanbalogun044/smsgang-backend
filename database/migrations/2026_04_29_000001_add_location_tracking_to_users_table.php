<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Add IP and location tracking for registration
            $table->string('registration_ip', 45)->nullable()->after('email_verified_at');
            $table->string('last_login_ip', 45)->nullable()->after('registration_ip');
            $table->json('registration_location')->nullable()->after('last_login_ip');
            $table->json('last_login_location')->nullable()->after('registration_location');
            $table->timestamp('last_login_at')->nullable()->after('last_login_location');

            // Index for location queries
            $table->index('registration_ip');
            $table->index('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['registration_ip']);
            $table->dropIndex(['last_login_ip']);
            $table->dropColumn([
                'registration_ip',
                'last_login_ip',
                'registration_location',
                'last_login_location',
                'last_login_at',
            ]);
        });
    }
};
