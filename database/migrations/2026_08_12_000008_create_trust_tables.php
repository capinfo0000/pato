<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 信頼・安全: 相互評価 / 通報。SOS・監視の運用と接続する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained();
            $table->foreignId('rater_user_id')->constrained('users');
            $table->foreignId('ratee_user_id')->constrained('users');
            $table->unsignedTinyInteger('stars'); // 1-5
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'rater_user_id', 'ratee_user_id']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users');
            $table->foreignId('target_user_id')->constrained('users');
            $table->foreignId('call_id')->nullable()->constrained();
            $table->enum('reason', ['harassment', 'external_solicit', 'danger', 'other']);
            $table->enum('status', ['open', 'reviewing', 'actioned', 'dismissed'])->default('open');
            $table->text('detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('reviews');
    }
};
