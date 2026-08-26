<?php

declare(strict_types=1);

namespace App\Application\Cast;

use App\Models\CastProfile;
use App\Models\CastScreening;
use App\Models\ClassTier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * キャスト審査。写真審査 → 面談 の二段階で進める。
 *
 * 状態: applied → photo_review → interview → approved / rejected
 * 承認時にクラスを付与して初めて稼働可能（is_active）になる。
 * 却下から3か月経過で再申込を許可する（運用ルール）。
 */
final class ScreeningService
{
    public const REAPPLY_AFTER_MONTHS = 3;

    /** @var list<string> */
    private const ORDER = ['applied', 'photo_review', 'interview'];

    /** キャストの審査申込。 */
    public function apply(User $user, string $displayName, ?int $areaId, ?int $age, ?string $bio): CastProfile
    {
        return DB::transaction(function () use ($user, $displayName, $areaId, $age, $bio) {
            $profile = CastProfile::where('user_id', $user->id)->first();

            if ($profile !== null && ! $this->canReapply($profile)) {
                throw new \DomainException('reapply_too_soon');
            }

            $profile = CastProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $displayName,
                    'home_area_id' => $areaId,
                    'age' => $age,
                    'bio' => $bio,
                    'screening_status' => 'applied',
                    'is_active' => false,
                    'availability' => 'offline',
                ],
            );

            CastScreening::create([
                'cast_profile_id' => $profile->id,
                'stage' => 'photo',
                'result' => 'pending',
            ]);

            return $profile;
        });
    }

    /** 次の段階へ進める（photo_review → interview）。 */
    public function advance(CastProfile $profile, User $reviewer, ?string $note = null): CastProfile
    {
        return DB::transaction(function () use ($profile, $reviewer, $note) {
            $current = $profile->screening_status;
            $index = array_search($current, self::ORDER, true);

            if ($index === false || $index === count(self::ORDER) - 1) {
                throw new \DomainException('cannot_advance');
            }

            $next = self::ORDER[$index + 1];

            CastScreening::create([
                'cast_profile_id' => $profile->id,
                'stage' => $next === 'interview' ? 'interview' : 'photo',
                'result' => 'passed',
                'reviewed_by' => $reviewer->id,
                'note' => $note,
                'reviewed_at' => now(),
            ]);

            $profile->update(['screening_status' => $next]);

            return $profile->fresh();
        });
    }

    /** 承認してクラスを付与する（ここで初めて稼働可能になる）。 */
    public function approve(CastProfile $profile, User $reviewer, string $classCode, ?string $note = null): CastProfile
    {
        return DB::transaction(function () use ($profile, $reviewer, $classCode, $note) {
            if ($profile->screening_status === 'approved') {
                throw new \DomainException('already_approved');
            }

            $tierId = ClassTier::where('code', $classCode)->value('id')
                ?? throw new \InvalidArgumentException("未知のクラス: {$classCode}");

            CastScreening::create([
                'cast_profile_id' => $profile->id,
                'stage' => 'interview',
                'result' => 'passed',
                'reviewed_by' => $reviewer->id,
                'note' => $note,
                'reviewed_at' => now(),
            ]);

            $profile->update([
                'screening_status' => 'approved',
                'class_tier_id' => $tierId,
                'is_active' => true,
            ]);
            Audit::log('screening.approved', $profile, ['class' => $classCode]);

            return $profile->fresh();
        });
    }

    /** 却下する。 */
    public function reject(CastProfile $profile, User $reviewer, ?string $note = null): CastProfile
    {
        return DB::transaction(function () use ($profile, $reviewer, $note) {
            CastScreening::create([
                'cast_profile_id' => $profile->id,
                'stage' => $profile->screening_status === 'interview' ? 'interview' : 'photo',
                'result' => 'failed',
                'reviewed_by' => $reviewer->id,
                'note' => $note,
                'reviewed_at' => now(),
            ]);

            $profile->update([
                'screening_status' => 'rejected',
                'is_active' => false,
                'availability' => 'offline',
            ]);
            Audit::log('screening.rejected', $profile);

            return $profile->fresh();
        });
    }

    /** 却下から所定期間が経過していれば再申込できる。 */
    public function canReapply(CastProfile $profile): bool
    {
        if ($profile->screening_status !== 'rejected') {
            // 審査中・承認済みは再申込不可（重複申込を防ぐ）
            return false;
        }

        $lastRejection = CastScreening::where('cast_profile_id', $profile->id)
            ->where('result', 'failed')
            ->latest('reviewed_at')
            ->first();

        return $lastRejection?->reviewed_at === null
            || $lastRejection->reviewed_at->addMonths(self::REAPPLY_AFTER_MONTHS)->isPast();
    }
}
