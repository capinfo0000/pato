<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 料金マスタ: エリア・店舗・クラス・エリア別料金。
 * 料金はエリア×クラスで持ち、テイクレート/加算も設定値で管理する（docs/06）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // 例: 岡山市中心部
            $table->boolean('serviceable')->default(true);
            $table->timestamps();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained();
            $table->string('name');
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('class_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // premium|vip|royal_vip
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('area_class_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained();
            $table->foreignId('class_tier_id')->constrained();
            $table->unsignedInteger('points_per_30min'); // ゲスト課金(有償P)
            $table->unsignedInteger('take_rate_bp')->default(4000);        // 運営取り分 40%
            $table->unsignedInteger('nomination_surcharge_bp')->default(2000); // 指名 +20%
            $table->unsignedInteger('night_surcharge_bp')->default(2000);      // 深夜 +20%
            $table->date('effective_from')->nullable();
            $table->timestamps();

            $table->unique(['area_id', 'class_tier_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_class_prices');
        Schema::dropIfExists('class_tiers');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('areas');
    }
};
