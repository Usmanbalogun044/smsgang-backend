<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'operation_type')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('operation_type', 30)->nullable()->after('status');
                $table->index(['user_id', 'operation_type', 'created_at'], 'transactions_user_operation_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'operation_type')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex('transactions_user_operation_created_idx');
                $table->dropColumn('operation_type');
            });
        }
    }
};
