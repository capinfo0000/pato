@extends('layouts.app')
@section('title', '審査キュー｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>キャスト審査キュー</h2>
        <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:6px">
            @foreach ([
                'applied' => '受付', 'photo_review' => '写真審査',
                'interview' => '面談', 'approved' => '承認済', 'rejected' => '却下',
            ] as $key => $label)
                <a href="{{ route('admin.screenings.index', ['status' => $key]) }}"
                   class="badge {{ $status === $key ? 'now' : '' }}" style="white-space:nowrap;padding:6px 10px">
                    {{ $label }} {{ $counts[$key] ?? 0 }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="card">
        @forelse ($profiles as $profile)
            <a class="row" href="{{ route('admin.screenings.show', $profile) }}">
                <span>
                    <span class="v">{{ $profile->display_name }}</span>
                    <span class="k" style="display:block">
                        {{ $profile->homeArea?->name }} / {{ $profile->age }}歳
                        @if ($profile->classTier) / {{ $profile->classTier->name }} @endif
                    </span>
                </span>
                <span class="k">{{ $profile->created_at?->format('n/j') }}</span>
            </a>
        @empty
            <p class="muted">この状態のキャストはいません。</p>
        @endforelse
        <div style="margin-top:12px">{{ $profiles->links() }}</div>
    </div>
@endsection
