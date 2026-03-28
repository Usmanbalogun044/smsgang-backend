<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'transactions_user_created_idx');
            $table->index(['user_id', 'operation_type', 'created_at'], 'transactions_user_op_created_idx');
            $table->index(['user_id', 'type', 'created_at'], 'transactions_user_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_user_created_idx');
            $table->dropIndex('transactions_user_op_created_idx');
            $table->dropIndex('transactions_user_type_created_idx');
        });
    }
};
