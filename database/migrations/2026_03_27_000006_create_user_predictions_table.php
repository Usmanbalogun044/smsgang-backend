<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_predictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contest_id');
            $table->unsignedBigInteger('question_id');
            $table->string('predicted_answer');
            $table->bigInteger('points_earned')->default(0);  // 0 if wrong, points_value if right
            $table->boolean('is_correct')->default(false);
            $table->bigInteger('contest_total_score')->default(0);  // Total score for this user in contest
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('contest_id')->references('id')->on('prediction_contests')->onDelete('cascade');
            $table->foreign('question_id')->references('id')->on('prediction_questions')->onDelete('cascade');
            $table->unique(['user_id', 'contest_id', 'question_id']);
            $table->index('user_id');
            $table->index('contest_id');
            $table->index('is_correct');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_predictions');
    }
};
