<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ゲーミフィケーション: キャストKPI・ファンポイント・バッジ・称号・ランキング。
 * 供給（キャスト）側の動機付けの中核。docs/00 §4.11 / docs/02 §3b。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cast_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cast_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('extend_rate_x10')->default(0);  // 延長率（星×10）
            $table->unsignedInteger('repeat_rate_x10')->default(0);  // サービスリピート率
            $table->unsignedInteger('remeet_rate_x10')->default(0);  // また会いたい率
            $table->unsignedInteger('fan_points_total')->default(0);
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();

            $table->unique('cast_profile_id');
            $table->index('fan_points_total');
        });

        Schema::create('fan_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cast_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained();
            $table->integer('points');
            $table->string('reason'); // completed_call | tip | review | badge
            $table->timestamps();

            $table->index(['cast_profile_id', 'created_at']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // healing | sparkle | humor | diva ...
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('badge_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained();
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('cast_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['cast_profile_id', 'badge_id']);
        });

        Schema::create('awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cast_profile_id')->constrained()->cascadeOnDelete();
            $table->string('event_code'); // cinderella_race | tenka ...
            $table->string('title');      // 合流時間部門Sランク 等
            $table->string('season');     // 2025 等
            $table->timestamps();
        });

        Schema::create('rankings', function (Blueprint $table) {
            $table->id();
            $table->enum('subject', ['guest', 'cast']);
            $table->enum('period', ['yesterday', 'last_week', 'last_month', 'this_month', 'half', 'year', 'all']);
            $table->foreignId('area_id')->nullable()->constrained(); // null = 全国
            $table->string('category')->default('総合');
            $table->unsignedBigInteger('ref_id'); // user_id or cast_profile_id
            $table->unsignedInteger('rank');
            $table->unsignedInteger('score');
            $table->date('computed_on');
            $table->timestamps();

            $table->index(['subject', 'period', 'computed_on', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rankings');
        Schema::dropIfExists('awards');
        Schema::dropIfExists('badge_grants');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('fan_points');
        Schema::dropIfExists('cast_kpis');
    }
};
