<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'user_id') && Schema::hasColumn('transactions', 'created_at')) {
                if (! $this->indexExists('transactions', 'transactions_user_created_idx')) {
                    $table->index(['user_id', 'created_at'], 'transactions_user_created_idx');
                }
            }

            if (
                Schema::hasColumn('transactions', 'user_id')
                && Schema::hasColumn('transactions', 'type')
                && Schema::hasColumn('transactions', 'created_at')
            ) {
                if (! $this->indexExists('transactions', 'transactions_user_type_created_idx')) {
                    $table->index(['user_id', 'type', 'created_at'], 'transactions_user_type_created_idx');
                }
            }

            // Backward compatibility for environments that still have operation_type.
            if (
                Schema::hasColumn('transactions', 'user_id')
                && Schema::hasColumn('transactions', 'operation_type')
                && Schema::hasColumn('transactions', 'created_at')
            ) {
                if (! $this->indexExists('transactions', 'transactions_user_op_created_idx')) {
                    $table->index(['user_id', 'operation_type', 'created_at'], 'transactions_user_op_created_idx');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            if ($this->indexExists('transactions', 'transactions_user_created_idx')) {
                $table->dropIndex('transactions_user_created_idx');
            }

            if ($this->indexExists('transactions', 'transactions_user_op_created_idx')) {
                $table->dropIndex('transactions_user_op_created_idx');
            }

            if ($this->indexExists('transactions', 'transactions_user_type_created_idx')) {
                $table->dropIndex('transactions_user_type_created_idx');
            }
        });
    }
};
