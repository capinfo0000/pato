<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メッセージ: 呼び出し単位のグループチャット / 個別 / コンシェルジュ(公式)。
 * 本文は NG 検知(ContentFilter)でフラグ付けし、運用に委ねる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('kind', ['call', 'direct', 'concierge'])->default('call');
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users');
            $table->text('body');
            $table->boolean('flagged')->default(false);
            $table->json('flag_reasons')->nullable(); // ContentFilter の理由コード
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('threads');
    }
};
