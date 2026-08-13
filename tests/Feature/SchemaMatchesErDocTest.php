<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ER図(docs/02_er_diagram.md)の「実装済み」宣言と、実際のマイグレーションの整合を守る。
 *
 * 実装中に cast_screenings のマイグレーション漏れが起きたため、同じ事故を防ぐ回帰テスト。
 * 新しいテーブルを足したら、ER図の「実装済み」一覧にも必ず追記すること。
 */
final class SchemaMatchesErDocTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_declared_implemented_in_the_er_doc_exists(): void
    {
        foreach ($this->declaredImplementedTables() as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "ER図で実装済みとされている {$table} が存在しない。マイグレーションを追加するか、ER図の記載を直すこと。",
            );
        }
    }

    public function test_tables_declared_unimplemented_are_really_absent(): void
    {
        // 「未実装」と書いたテーブルが実は存在する＝ドキュメントが古い
        foreach (['cast_tags', 'coupons', 'coupon_grants', 'gifts', 'passes', 'referrals'] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "{$table} は実装されている。ER図の「未実装」一覧から実装済みへ移すこと。",
            );
        }
    }

    /**
     * ER図の「実装済み」節からテーブル名を抜き出す。
     *
     * @return list<string>
     */
    private function declaredImplementedTables(): array
    {
        $doc = (string) file_get_contents(base_path('docs/02_er_diagram.md'));

        $start = strpos($doc, '**実装済み（');
        $end = strpos($doc, '**未実装（');
        $this->assertNotFalse($start, 'ER図に「実装済み」節が見つからない');
        $this->assertNotFalse($end, 'ER図に「未実装」節が見つからない');

        $section = substr($doc, $start, $end - $start);
        preg_match_all('/`([a-z_]+)`/', $section, $matches);

        $tables = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($tables, 'ER図の実装済み一覧が空');

        return $tables;
    }
}
