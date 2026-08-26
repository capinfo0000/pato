<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOS通報。合流中に危険を感じたとき、ゲスト・キャストのどちらからでも即時通報できる。
 * 位置情報は任意（同意した場合のみ）。PII なので保持期間と閲覧権限を絞ること。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('call_id')->nullable()->constrained();
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            $table->string('note')->nullable();
            $table->string('location_hint')->nullable(); // 任意の位置メモ（PII）
            $table->foreignId('handled_by')->nullable()->constrained('users');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_events');
    }
};
