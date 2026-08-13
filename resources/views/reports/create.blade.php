@extends('layouts.app')
@section('title', '通報｜pato岡山')

@section('content')
    <div class="card">
        <h2>通報する</h2>
        <p class="sub">{{ $target->nickname }} さんについて運営に報告します。</p>

        <div class="warn" style="margin-bottom:12px">
            緊急の危険がある場合は、まず110番など公的機関にご連絡ください。
        </div>

        @if ($errors->any())
            <div class="flash err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif

        <form method="POST" action="{{ route('reports.store') }}">
            @csrf
            <input type="hidden" name="target_user_id" value="{{ $target->id }}">
            @if ($callId)<input type="hidden" name="call_id" value="{{ $callId }}">@endif

            <label>理由</label>
            <select name="reason">
                @foreach ($reasons as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>

            <label>詳細（任意）</label>
            <textarea name="detail" rows="4" placeholder="状況をご記入ください"></textarea>

            <div style="height:8px"></div>
            <button class="btn" type="submit">通報する</button>
        </form>
        <p class="hint" style="text-align:left">通報内容は運営のみが確認します。相手には通知されません。</p>
    </div>
@endsection
