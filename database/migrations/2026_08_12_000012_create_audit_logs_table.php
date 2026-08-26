<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 監査ログ。運営の重要操作（制裁・精算承認・料金変更・審査結果）を追記のみで残す。
 * 金銭と人の処遇に関わる操作は、後から「誰が・いつ・何を」を再現できる必要がある。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('action');            // 例: payout.approved
            $table->string('subject_type')->nullable(); // 例: App\Models\Payout
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable(); // PII を含めないこと
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
