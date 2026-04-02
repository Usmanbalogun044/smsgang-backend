<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_online')->default(false)->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('is_online');
            $table->text('last_user_agent')->nullable()->after('last_login_ip');
            $table->timestamp('last_login_at')->nullable()->after('last_user_agent');
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
            $table->timestamp('last_logout_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'is_online',
                'last_login_ip',
                'last_user_agent',
                'last_login_at',
                'last_seen_at',
                'last_logout_at',
            ]);
        });
    }
};
