@extends('layouts.app')
@section('title', '通報対応｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>通報対応</h2>
        <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:6px">
            @foreach (['open' => '未対応', 'reviewing' => '対応中', 'actioned' => '対応済', 'dismissed' => '却下'] as $key => $label)
                <a href="{{ route('admin.reports.index', ['status' => $key]) }}"
                   class="badge {{ $status === $key ? 'now' : '' }}" style="white-space:nowrap;padding:6px 10px">
                    {{ $label }} {{ $counts[$key] ?? 0 }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="card">
        @forelse ($reports as $report)
            <div class="row" style="flex-wrap:wrap">
                <span style="width:100%">
                    <span class="v">{{ $reasons[$report->reason] ?? $report->reason }}</span>
                    <span class="k" style="display:block">
                        通報者 {{ $report->reporter?->nickname }} → 対象 {{ $report->target?->nickname }}
                        （{{ $report->target?->status }}）
                        @if ($report->call_id) / 呼び出し #{{ $report->call_id }} @endif
                    </span>
                    @if ($report->detail)
                        <span class="k" style="display:block">{{ $report->detail }}</span>
                    @endif
                </span>
                <span style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
                    @if ($report->status === 'open')
                        <form method="POST" action="{{ route('admin.reports.review', $report) }}">@csrf
                            <button class="btn secondary" style="width:auto;padding:8px 12px" type="submit">対応中に</button>
                        </form>
                    @endif
                    @if (in_array($report->status, ['open', 'reviewing'], true))
                        <form method="POST" action="{{ route('admin.reports.action', $report) }}">@csrf
                            <button class="btn secondary" style="width:auto;padding:8px 12px" type="submit">対応済</button>
                        </form>
                        <form method="POST" action="{{ route('admin.reports.action', $report) }}"
                              onsubmit="return confirm('対象アカウントを停止しますか？');">@csrf
                            <input type="hidden" name="suspend" value="1">
                            <button class="btn" style="width:auto;padding:8px 12px" type="submit">対応済＋停止</button>
                        </form>
                        <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">@csrf
                            <button class="btn secondary" style="width:auto;padding:8px 12px" type="submit">却下</button>
                        </form>
                    @endif
                    @if ($report->target?->status === 'suspended')
                        <form method="POST" action="{{ route('admin.reports.reinstate', $report) }}">@csrf
                            <button class="btn secondary" style="width:auto;padding:8px 12px" type="submit">停止解除</button>
                        </form>
                    @endif
                </span>
            </div>
        @empty
            <p class="muted">この状態の通報はありません。</p>
        @endforelse
        <div style="margin-top:12px">{{ $reports->links() }}</div>
    </div>

    <div class="card">
        <h2>検知されたメッセージ</h2>
        <p class="sub">NG検知でフラグが立った直近の投稿です。</p>
        @forelse ($flaggedMessages as $message)
            <div class="row">
                <span>
                    <span class="v">{{ $message->sender?->nickname }}</span>
                    <span class="k" style="display:block">{{ \Illuminate\Support\Str::limit($message->body, 40) }}</span>
                </span>
                <span class="k">{{ implode('/', (array) $message->flag_reasons) }}</span>
            </div>
        @empty
            <p class="muted">検知されたメッセージはありません。</p>
        @endforelse
    </div>
@endsection
