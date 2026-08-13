@extends('layouts.app')
@section('title', '審査詳細｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>{{ $profile->display_name }}</h2>
        <p class="sub">{{ $profile->bio }}</p>
        <div class="row"><span class="k">状態</span><span class="v">{{ $profile->screening_status }}</span></div>
        <div class="row"><span class="k">エリア</span><span class="v">{{ $profile->homeArea?->name }}</span></div>
        <div class="row"><span class="k">年齢</span><span class="v">{{ $profile->age }}歳</span></div>
        <div class="row"><span class="k">クラス</span><span class="v">{{ $profile->classTier?->name ?? '未付与' }}</span></div>
        <div class="row"><span class="k">稼働可能</span><span class="v">{{ $profile->is_active ? 'はい' : 'いいえ' }}</span></div>
    </div>

    @if (! in_array($profile->screening_status, ['approved', 'rejected'], true))
        @if ($profile->screening_status !== 'interview')
            <form method="POST" action="{{ route('admin.screenings.advance', $profile) }}">@csrf
                <input type="text" name="note" placeholder="所見（任意）">
                <div style="height:8px"></div>
                <button class="btn" type="submit">次の段階へ進める</button>
            </form>
            <div style="height:8px"></div>
        @endif

        <div class="card">
            <h2>承認する</h2>
            <p class="sub">クラスを付与すると稼働可能になります。</p>
            <form method="POST" action="{{ route('admin.screenings.approve', $profile) }}">@csrf
                <label>付与するクラス</label>
                <select name="class">
                    <option value="premium">プレミアム</option>
                    <option value="vip">VIP</option>
                    <option value="royal_vip">ロイヤルVIP</option>
                </select>
                <input type="text" name="note" placeholder="所見（任意）">
                <div style="height:8px"></div>
                <button class="btn" type="submit">承認する</button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.screenings.reject', $profile) }}"
              onsubmit="return confirm('却下しますか？');">@csrf
            <input type="text" name="note" placeholder="却下理由（任意）">
            <div style="height:8px"></div>
            <button class="btn secondary" type="submit">却下する</button>
        </form>
        <div style="height:8px"></div>
    @endif

    <div class="card">
        <h2>審査履歴</h2>
        @forelse ($profile->screenings as $s)
            <div class="row">
                <span>
                    <span class="v">{{ $s->stage === 'photo' ? '写真' : '面談' }} / {{ $s->result }}</span>
                    <span class="k" style="display:block">{{ $s->note }}</span>
                </span>
                <span class="k">{{ $s->reviewed_at?->format('n/j H:i') }}</span>
            </div>
        @empty
            <p class="muted">履歴はまだありません。</p>
        @endforelse
    </div>

    <a class="btn secondary" href="{{ route('admin.screenings.index') }}">キューへ戻る</a>
@endsection
