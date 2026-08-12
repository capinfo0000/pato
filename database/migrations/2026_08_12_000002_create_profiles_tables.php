<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ゲスト/キャストのプロフィールと本人確認(eKYC)。
 * PII（生年月日・provider_ref）は暗号化保存し、ログに出さない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('avatar_path')->nullable();
            $table->unsignedInteger('grade_level')->default(0); // グレードランクアップ
            $table->timestamps();
        });

        Schema::create('cast_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->foreignId('class_tier_id')->nullable()->constrained();
            $table->foreignId('home_area_id')->nullable()->constrained('areas');
            $table->enum('screening_status', ['applied', 'photo_review', 'interview', 'approved', 'rejected'])->default('applied');
            $table->boolean('is_active')->default(false);
            $table->enum('availability', ['now', 'today', 'offline'])->default('offline');
            $table->boolean('in_session')->default(false); // 合流中
            $table->string('bio')->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'availability']);
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('method', ['ekyc'])->default('ekyc');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->boolean('is_adult')->default(false); // 18歳以上
            $table->text('birthdate_encrypted')->nullable(); // PII: 暗号化
            $table->string('provider_ref')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('cast_profiles');
        Schema::dropIfExists('guest_profiles');
    }
};
