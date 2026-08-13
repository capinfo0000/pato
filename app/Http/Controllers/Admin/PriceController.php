<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AreaClassPrice;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 料金マスタ管理。エリア×クラスの単価・テイクレート・加算率を運用中に調整できる。
 *
 * テイクレートは docs/06 の作業デフォルト(40%)を出発点に、供給状況を見ながら
 * ここで変更する想定。変更は effective_from を持つ行として扱う。
 */
final class PriceController extends Controller
{
    public function index(): View
    {
        return view('admin.prices.index', [
            'prices' => AreaClassPrice::with('area', 'classTier')
                ->orderBy('area_id')
                ->orderBy('class_tier_id')
                ->get(),
            'areas' => Area::all(),
            'balance' => 0,
        ]);
    }

    public function update(Request $request, AreaClassPrice $price): RedirectResponse
    {
        $validated = $request->validate([
            'points_per_30min' => ['required', 'integer', 'min:0', 'max:1000000'],
            'take_rate_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'nomination_surcharge_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'night_surcharge_bp' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $before = $price->only(array_keys($validated));
        $price->update($validated);
        Audit::log('price.updated', $price, ['before' => json_encode($before), 'after' => json_encode($validated)]);

        return back()->with('status', '料金を更新しました。以降の呼び出しから適用されます。');
    }

    /** エリアの提供可否を切り替える（岡山限定の担保）。 */
    public function toggleArea(Area $area): RedirectResponse
    {
        $area->update(['serviceable' => ! $area->serviceable]);
        Audit::log('area.toggled', $area, ['serviceable' => $area->serviceable]);

        return back()->with('status', $area->name.'を'.($area->serviceable ? '提供中' : '停止').'にしました。');
    }
}
