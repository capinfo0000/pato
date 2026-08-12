<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * patoコール本体と参加キャスト。状態遷移は CallStateMachine 経由でのみ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_user_id')->constrained('users');
            $table->foreignId('area_id')->constrained();
            $table->foreignId('venue_id')->nullable()->constrained();
            $table->dateTime('start_at');
            $table->unsignedInteger('duration_min'); // 30単位
            $table->unsignedInteger('headcount');
            $table->unsignedInteger('hold_points');
            $table->enum('status', ['draft', 'open', 'matched', 'in_progress', 'completed', 'canceled', 'expired'])->default('draft');
            $table->boolean('is_mix')->default(false);
            $table->foreignId('nominated_cast_profile_id')->nullable()->constrained('cast_profiles');
            $table->unsignedInteger('priority_surcharge_points')->default(0);
            $table->boolean('is_night')->default(false);
            $table->enum('venue_kind', ['restaurant', 'bar', 'public'])->nullable(); // 密室禁止(法務04)
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'area_id', 'start_at']);
        });

        // クラス×人数の明細（ミックス対応）
        Schema::create('call_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_tier_id')->constrained();
            $table->unsignedInteger('headcount');
            $table->boolean('nominated')->default(false);
        });

        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cast_profile_id')->constrained();
            $table->enum('status', ['applied', 'accepted', 'joined', 'completed', 'no_show', 'canceled'])->default('applied');
            $table->unsignedInteger('tip_points')->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'cast_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');
        Schema::dropIfExists('call_line_items');
        Schema::dropIfExists('calls');
    }
};
