<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add only the missing JSON columns to avoid duplicate column errors
        if (! Schema::hasColumn('users', 'registration_location') || ! Schema::hasColumn('users', 'last_login_location')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'registration_location')) {
                    $table->json('registration_location')->nullable()->after('last_login_ip');
                }

                if (! Schema::hasColumn('users', 'last_login_location')) {
                    $table->json('last_login_location')->nullable()->after('registration_location');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'last_login_location')) {
                $table->dropColumn('last_login_location');
            }

            if (Schema::hasColumn('users', 'registration_location')) {
                $table->dropColumn('registration_location');
            }
        });
    }
};
