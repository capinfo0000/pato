<?php

declare(strict_types=1);

namespace App\Application\Trust;

use App\Domain\Call\Enums\CallStatus;
use App\Models\Call;
use App\Models\CastKpi;
use App\Models\CastProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 完了した呼び出しへの相互評価。星＋定型タグ。
 *
 * ゲスト→キャストのレビューから、キャストのKPI（延長率・リピート率・また会いたい率）を
 * 再計算する。KPI は星（x10 の整数）で保持する。
 */
final class ReviewService
{
    /** ゲストが選べる定型タグ。 */
    public const GUEST_TAGS = ['楽しかった', '気配りが良い', '話が面白い', '時間ぴったり', 'また会いたい'];

    /**
     * レビューを投稿する。完了した呼び出しにのみ、参加者同士で1回ずつ。
     *
     * @param  list<string>  $tags
     */
    public function post(Call $call, User $rater, User $ratee, int $stars, array $tags = [], ?string $comment = null): Review
    {
        if ($stars < 1 || $stars > 5) {
            throw new \InvalidArgumentException('stars は1〜5');
        }

        return DB::transaction(function () use ($call, $rater, $ratee, $stars, $tags, $comment) {
            $call->refresh();

            if ($call->status !== CallStatus::Completed) {
                throw new \DomainException('call_not_completed');
            }
            if (! $this->isParticipant($call, $rater) || ! $this->isParticipant($call, $ratee)) {
                throw new \DomainException('not_a_participant');
            }
            if ($rater->id === $ratee->id) {
                throw new \DomainException('cannot_review_self');
            }
            if (Review::where('call_id', $call->id)
                ->where('rater_user_id', $rater->id)
                ->where('ratee_user_id', $ratee->id)
                ->exists()
            ) {
                throw new \DomainException('already_reviewed');
            }

            $review = Review::create([
                'call_id' => $call->id,
                'rater_user_id' => $rater->id,
                'ratee_user_id' => $ratee->id,
                'stars' => $stars,
                'tags' => $tags === [] ? null : $tags,
                'comment' => $comment,
            ]);

            // 評価されたのがキャストなら KPI を再計算する
            $castProfile = CastProfile::where('user_id', $ratee->id)->first();
            if ($castProfile !== null) {
                $this->recalculateKpi($castProfile);
            }

            return $review;
        });
    }

    /**
     * キャストのKPIを、受け取ったレビューと実績から再計算する。
     *
     * - また会いたい率: 「また会いたい」タグの付与率 → 星換算
     * - リピート率: 同じゲストから2回以上呼ばれた割合 → 星換算
     * - 延長率: 延長された（設定時間が最低1時間を超えた）呼び出しの割合 → 星換算
     */
    public function recalculateKpi(CastProfile $cast): CastKpi
    {
        $reviews = Review::where('ratee_user_id', $cast->user_id)->get();
        $completed = Call::where('status', CallStatus::Completed)
            ->whereHas('participants', fn ($q) => $q->where('cast_profile_id', $cast->id))
            ->get();

        $remeet = $reviews->isEmpty()
            ? 0
            : $reviews->filter(fn (Review $r) => in_array('また会いたい', (array) $r->tags, true))->count() / $reviews->count();

        $guestCounts = $completed->groupBy('guest_user_id')->map->count();
        $repeat = $completed->isEmpty()
            ? 0
            : $guestCounts->filter(fn (int $n) => $n >= 2)->sum() / max(1, $completed->count());

        $extended = $completed->isEmpty()
            ? 0
            : $completed->filter(fn (Call $c) => $c->duration_min > 60)->count() / $completed->count();

        return tap(CastKpi::firstOrCreate(['cast_profile_id' => $cast->id]), function (CastKpi $kpi) use ($remeet, $repeat, $extended) {
            $kpi->update([
                'remeet_rate_x10' => (int) round($remeet * 50),   // 0〜1 を 星0〜5(x10) に
                'repeat_rate_x10' => (int) round($repeat * 50),
                'extend_rate_x10' => (int) round($extended * 50),
                'recalculated_at' => now(),
            ]);
        })->fresh();
    }

    private function isParticipant(Call $call, User $user): bool
    {
        if ($call->guest_user_id === $user->id) {
            return true;
        }

        $castProfileId = CastProfile::where('user_id', $user->id)->value('id');

        return $castProfileId !== null
            && $call->participants()->where('cast_profile_id', $castProfileId)->exists();
    }
}
