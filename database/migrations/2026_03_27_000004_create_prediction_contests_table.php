<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_contests', function (Blueprint $table) {
            $table->id();
            $table->string('external_match_id')->unique();  // From sports API
            $table->string('home_team');
            $table->string('away_team');
            $table->dateTime('match_start_time');
            $table->enum('sport', ['football', 'basketball', 'cricket', 'tennis'])->default('football');
            $table->enum('status', ['upcoming', 'locked', 'live', 'finished', 'cancelled'])->default('upcoming');
            $table->decimal('entry_fee_ngn', 12, 2)->default(1000);
            $table->bigInteger('total_entries')->default(0);
            $table->decimal('total_pool_ngn', 12, 2)->default(0);
            $table->decimal('platform_cut_ngn', 12, 2)->default(0);  // 20%
            $table->decimal('prize_pool_ngn', 12, 2)->default(0);  // 80%
            $table->bigInteger('minimum_winning_score')->default(6);  // Minimum points to win
            $table->json('match_data')->nullable();  // Full API response
            $table->json('match_result')->nullable();  // Actual result from API
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sport')->references('name')->on('sports')->onDelete('restrict');
            $table->index('external_match_id');
            $table->index('status');
            $table->index('match_start_time');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_contests');
    }
};
