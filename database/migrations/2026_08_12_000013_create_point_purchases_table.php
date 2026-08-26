<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ポイント購入の記録。決済参照IDと紐づけ、返金時にどのポイントを回収するか特定する。
 *
 * これが無いと「返金されたがどの購入か分からない」状態になり、回収できない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('point_wallets');
            $table->foreignId('product_id')->nullable()->constrained('point_products');
            $table->unsignedInteger('paid_points');
            $table->unsignedInteger('price_yen');
            $table->string('charge_ref')->unique(); // PSP の決済参照ID
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedInteger('refunded_points')->default(0);
            $table->timestamps();

            $table->index('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_purchases');
    }
};
