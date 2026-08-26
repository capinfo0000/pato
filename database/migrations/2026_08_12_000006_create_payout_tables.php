<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * キャスト精算: 完了呼び出しから報酬を計上 → 出金申請 → 承認 → 送金。
 * キャストは個人事業主（税は自己申告）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cast_wallet_id')->constrained('point_wallets');
            $table->unsignedInteger('amount_points');
            $table->enum('status', ['accrued', 'requested', 'approved', 'paid', 'rejected'])->default('accrued');
            $table->enum('speed', ['normal', 'express'])->default('normal'); // 翌月末 / 早期振込
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->nullable()->constrained(); // 未申請は null
            $table->foreignId('call_participant_id')->constrained();
            $table->unsignedInteger('amount_points');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
    }
};
