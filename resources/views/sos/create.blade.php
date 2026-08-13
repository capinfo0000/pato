@extends('layouts.app')
@section('title', 'SOS｜pato岡山')

@section('content')
    <div class="card">
        <h2 style="color:#b3261e">SOSを送る</h2>
        <div class="flash err" style="margin-bottom:12px">
            <b>危険が差し迫っている場合は、まず110番へ通報してください。</b><br>
            このボタンは運営への緊急連絡です。
        </div>

        <form method="POST" action="{{ route('sos.store') }}">
            @csrf
            @if ($callId)<input type="hidden" name="call_id" value="{{ $callId }}">@endif

            <label>状況（任意）</label>
            <textarea name="note" rows="3" placeholder="何が起きているか、わかる範囲で"></textarea>

            <label>現在地のメモ（任意）</label>
            <input type="text" name="location_hint" placeholder="店名・目印など">
            <p class="hint" style="text-align:left">※運営のみが確認します</p>

            <div style="height:8px"></div>
            <button class="btn" style="background:#b3261e" type="submit">SOSを送信する</button>
        </form>
    </div>
@endsection
