<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * 法定表示のページ。未ログインでも閲覧できる必要がある。
 *
 * 事業者情報・届出番号は config/pato.php（環境変数）から取る。
 * 他社の番号を書かないこと（docs/07_release_gate.md）。
 */
final class LegalController extends Controller
{
    public function terms(): View
    {
        return view('legal.terms', $this->shared());
    }

    public function privacy(): View
    {
        return view('legal.privacy', $this->shared());
    }

    /** 特定商取引法に基づく表示。 */
    public function commerce(): View
    {
        return view('legal.commerce', $this->shared());
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'operator' => config('pato.operator'),
            'registration' => config('pato.internet_dating_registration'),
            'point' => config('pato.point'),
            'balance' => 0,
        ];
    }
}
