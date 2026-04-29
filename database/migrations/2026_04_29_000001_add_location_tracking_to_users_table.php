<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make migration idempotent: add columns only if they do not exist
        if (! Schema::hasColumn('users', 'registration_ip') || ! Schema::hasColumn('users', 'last_login_ip') || ! Schema::hasColumn('users', 'registration_location') || ! Schema::hasColumn('users', 'last_login_location') || ! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'registration_ip')) {
                    $table->string('registration_ip', 45)->nullable()->after('email_verified_at');
                }

                if (! Schema::hasColumn('users', 'last_login_ip')) {
                    $table->string('last_login_ip', 45)->nullable()->after('registration_ip');
                }

                if (! Schema::hasColumn('users', 'registration_location')) {
                    $table->json('registration_location')->nullable()->after('last_login_ip');
                }

                if (! Schema::hasColumn('users', 'last_login_location')) {
                    $table->json('last_login_location')->nullable()->after('registration_location');
                }

                if (! Schema::hasColumn('users', 'last_login_at')) {
                    $table->timestamp('last_login_at')->nullable()->after('last_login_location');
                }

                // Index for location queries (create if missing)
                // Avoid duplicate index errors by checking existence
                try {
                    $table->index('registration_ip');
                } catch (\Throwable $e) {
                    // ignore - index may already exist
                }

                try {
                    $table->index('last_login_ip');
                } catch (\Throwable $e) {
                    // ignore - index may already exist
                }
            });
        }
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
