<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contest_id');
            $table->string('question', 500);
            $table->json('options');  // ["Arsenal", "Chelsea", "Draw"]
            $table->string('correct_answer')->nullable();
            $table->bigInteger('points_value')->default(2);  // How many points if correct
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('hard');
            $table->enum('question_type', [
                'winner',           // Who wins the match
                'total_goals',      // Total goals in match
                'exact_score',      // Exact final score
                'first_goal',       // Which team scores first
                'both_score',       // Will both teams score
                'over_under',       // Over/Under on goals
                'corners',          // Total corners
                'cards',            // Total yellow/red cards
                'specific_player',  // Will player score
                'handicap'          // Asian handicap
            ])->default('winner');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('contest_id')->references('id')->on('prediction_contests')->onDelete('cascade');
            $table->index('contest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_questions');
    }
};
