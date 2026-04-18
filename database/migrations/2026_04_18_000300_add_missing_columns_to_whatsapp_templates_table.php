<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_templates')) {
            return;
        }

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_templates', 'category')) {
                $table->string('category', 40)->default('utility');
            }

            if (! Schema::hasColumn('whatsapp_templates', 'content_sid')) {
                $table->string('content_sid', 64)->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'body_preview')) {
                $table->text('body_preview')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'variables_schema')) {
                $table->json('variables_schema')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'unit_price_ngn')) {
                $table->decimal('unit_price_ngn', 12, 2)->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (! Schema::hasColumn('whatsapp_templates', 'provider_status')) {
                $table->string('provider_status', 40)->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'approval_status')) {
                $table->string('approval_status', 40)->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'approval_reason')) {
                $table->text('approval_reason')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'provider_payload')) {
                $table->json('provider_payload')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'metadata')) {
                $table->json('metadata')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'approval_requested_at')) {
                $table->timestamp('approval_requested_at')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_templates', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally no-op for safe rollback on mixed legacy schemas.
    }
};
