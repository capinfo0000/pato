<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ポイント: ウォレット・購入商品・台帳。
 * 残高は台帳(point_transactions)の合算で表現する（残高カラムを持たない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('point_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('paid_points');
            $table->unsignedInteger('price_yen');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('point_wallets');
            $table->enum('type', ['purchase', 'grant', 'hold', 'capture', 'release', 'tip', 'expire', 'payout_debit']);
            $table->enum('kind', ['paid', 'free']);
            $table->integer('points'); // 正の絶対量。符号は type が決める
            $table->foreignId('call_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('point_products');
            $table->date('expires_on')->nullable(); // 付与から180日
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['wallet_id', 'type']);
            $table->index(['expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('point_products');
        Schema::dropIfExists('point_wallets');
    }
};
