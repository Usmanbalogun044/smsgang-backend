<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_winners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contest_id');
            $table->bigInteger('rank');  // 1st, 2nd, 3rd tier
            $table->string('tier', 50);  // "tier_1", "tier_2", "tier_3"
            $table->bigInteger('total_score');
            $table->decimal('prize_amount_ngn', 12, 2);
            $table->enum('status', ['calculated', 'pending_payment', 'paid', 'cancelled'])->default('calculated');
            $table->dateTime('claimed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('contest_id')->references('id')->on('prediction_contests')->onDelete('cascade');
            $table->unique(['user_id', 'contest_id']);
            $table->index('user_id');
            $table->index('contest_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_winners');
    }
};
