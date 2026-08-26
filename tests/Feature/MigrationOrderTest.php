<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * 外部キーが「まだ存在しないテーブル」を参照していないことを検証する。
 *
 * sqlite はマイグレーション実行時に参照先テーブルの存在を検査しないため、
 * 開発では通ってしまう。MySQL は拒否する（errno 1824）。
 * 実際に cast_profiles が class_tiers を先に参照していて、
 * **本番DBではマイグレーションが一切通らない**状態になっていた。
 *
 * CI の MySQL ジョブでも拾えるが、それは開発の最後に気づくということなので、
 * 手元の sqlite 実行でも即座に落ちるようにここで固定する。
 */
final class MigrationOrderTest extends TestCase
{
    public function test_no_foreign_key_references_a_table_created_later(): void
    {
        $created = [];
        $violations = [];

        foreach ($this->migrationFiles() as $path) {
            $source = (string) file_get_contents($path);
            $name = basename($path);

            $makes = $this->tablesCreatedIn($source);

            foreach ($this->tablesReferencedIn($source) as $column => $target) {
                if (! in_array($target, $created, true) && ! in_array($target, $makes, true)) {
                    $violations[] = "{$name}: {$column} -> {$target}（{$target} はまだ作られていない）";
                }
            }

            $created = array_merge($created, $makes);
        }

        $this->assertSame(
            [],
            $violations,
            "外部キーの参照先が後のマイグレーションで作られている。MySQL ではここで失敗する:\n  "
                .implode("\n  ", $violations),
        );
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        sort($files); // Laravel はファイル名順に実行する

        $this->assertNotEmpty($files, 'マイグレーションが見つからない');

        return $files;
    }

    /** @return list<string> */
    private function tablesCreatedIn(string $source): array
    {
        preg_match_all("/Schema::create\(\s*'([a-z_]+)'/", $source, $m);

        return $m[1];
    }

    /**
     * `foreignId('x_id')->constrained()` と `->references()->on()` の両方を拾う。
     *
     * @return array<string, string> カラム名 => 参照先テーブル
     */
    private function tablesReferencedIn(string $source): array
    {
        $refs = [];

        preg_match_all(
            "/foreignId\(\s*'([a-z_]+)'\s*\)((?:->\w+\([^)]*\))*)/",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [, $column, $chain]) {
            if (! preg_match("/->constrained\(\s*'?([a-z_]*)'?/", $chain, $c)) {
                continue; // 制約を張らない foreignId は対象外
            }
            $refs[$column] = $c[1] !== '' ? $c[1] : $this->inferTable($column);
        }

        preg_match_all("/->references\(\s*'[a-z_]+'\s*\)->on\(\s*'([a-z_]+)'/", $source, $raw);
        foreach ($raw[1] as $i => $table) {
            $refs["(references #{$i})"] = $table;
        }

        return $refs;
    }

    /** Laravel の推論と同じ規則: user_id -> users, class_tier_id -> class_tiers */
    private function inferTable(string $column): string
    {
        $base = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;

        return Str::plural($base);
    }
}
