<?php
declare(strict_types=1);

/** 件数見積もり。DataPark 実接続時は同じインターフェースの実装に差し替える。 */
interface CountBackend
{
    /**
     * @param list<array{tag_id:string,reason:string,weight:int}> $must
     * @param list<array{tag_id:string,reason:string,weight:int}> $should
     * @param list<array{tag_id:string,reason:string,weight:int}> $exclude
     * @return array{estimate:?int,method:string}
     */
    public function count(TagMaster $master, array $must, array $should, array $exclude): array;
}

/** タグ間の独立を仮定した概算（モック）。実データとは乖離するため目安としてのみ使う。 */
final class MockCountBackend implements CountBackend
{
    public const METHOD = '概算（タグ間の独立を仮定したモック。実データとは乖離します）';

    public function count(TagMaster $master, array $must, array $should, array $exclude): array
    {
        $n = $master->totalCompanies();
        $p = fn(array $s): float => $master->byId[$s['tag_id']]['count'] / $n;

        if ($must) {
            $base = 1.0;
            foreach ($must as $s) {
                $base *= $p($s);
            }
        } elseif ($should) {
            $noneMatched = 1.0;
            foreach ($should as $s) {
                $noneMatched *= 1.0 - $p($s);
            }
            $base = 1.0 - $noneMatched;
        } else {
            return ['estimate' => null, 'method' => self::METHOD];
        }
        foreach ($exclude as $s) {
            $base *= 1.0 - $p($s);
        }
        return ['estimate' => (int) round($n * $base), 'method' => self::METHOD];
    }
}

final class Query
{
    public const DEMOTED_WEIGHT = 4;
    public const MAX_MUST_RECOMMENDED = 3;
    public const MAX_RELAX_STEPS = 10;
    public const SCORING_RULE = 'スコア = Σ（一致した should タグの weight）。must を満たし exclude に該当しない企業を、スコア降順で並べる。';

    private static function clampWeight($value): int
    {
        if (!is_numeric($value)) {
            return 3;
        }
        return max(1, min(5, (int) $value));
    }

    /** @return list<array{tag_id:string,reason:string,weight:int}> */
    private static function resolveList($items, TagMaster $master, string $label, array &$warnings, bool $withWeight = false): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rawId = $item['tag_id'] ?? null;
            $tag = $master->resolve($rawId);
            if ($tag === null) {
                $warnings[] = sprintf('%s: カタログに存在しないタグ番号 %s を除外しました。', $label, var_export($rawId, true));
                continue;
            }
            if (isset($seen[$tag['id']])) {
                $warnings[] = sprintf('%s: タグ %s が重複していたため1件にまとめました。', $label, $tag['id']);
                continue;
            }
            $seen[$tag['id']] = true;
            $out[] = [
                'tag_id' => $tag['id'],
                'reason' => (string) ($item['reason'] ?? ''),
                'weight' => $withWeight ? self::clampWeight($item['weight'] ?? null) : 0,
            ];
        }
        return $out;
    }

    /** モデル出力のタグ選択を検証し、must > exclude > should の優先で矛盾を解消する。 */
    public static function normalize(array $rawSelection, TagMaster $master, array &$warnings): array
    {
        $must = self::resolveList($rawSelection['must'] ?? [], $master, 'must', $warnings);
        $should = self::resolveList($rawSelection['should'] ?? [], $master, 'should', $warnings, true);
        $exclude = self::resolveList($rawSelection['exclude'] ?? [], $master, 'exclude', $warnings);

        $mustIds = array_column($must, 'tag_id');
        $excludeIds = array_column($exclude, 'tag_id');

        $conflict = array_values(array_intersect($excludeIds, $mustIds));
        if ($conflict) {
            sort($conflict);
            $warnings[] = sprintf('must と exclude の両方にあるタグ [%s] は must を優先し exclude から外しました。', implode(', ', $conflict));
            $exclude = array_values(array_filter($exclude, fn($s) => !in_array($s['tag_id'], $conflict, true)));
            $excludeIds = array_column($exclude, 'tag_id');
        }

        $blocked = array_merge($mustIds, $excludeIds);
        $dropped = array_values(array_map(
            fn($s) => $s['tag_id'],
            array_filter($should, fn($s) => in_array($s['tag_id'], $blocked, true))
        ));
        if ($dropped) {
            $warnings[] = sprintf('must/exclude と重複する should タグ [%s] を should から外しました。', implode(', ', $dropped));
            $should = array_values(array_filter($should, fn($s) => !in_array($s['tag_id'], $blocked, true)));
        }

        if (count($must) > self::MAX_MUST_RECOMMENDED) {
            $warnings[] = sprintf(
                'must が %d 個あります（推奨は %d 個以下）。AND 条件が多いと該当企業が急減します。',
                count($must),
                self::MAX_MUST_RECOMMENDED
            );
        }
        return [$must, $should, $exclude];
    }

    /**
     * 推定件数が minCount 未満の間、最も社数の少ない must を should に降格して条件を緩める。
     *
     * @return array{0:array,1:array,2:array,3:array}
     */
    public static function relaxUntil(TagMaster $master, CountBackend $backend, array $must, array $should, array $exclude, int $minCount): array
    {
        $steps = [];
        $result = $backend->count($master, $must, $should, $exclude);
        while ($result['estimate'] !== null && $result['estimate'] < $minCount && $must && count($steps) < self::MAX_RELAX_STEPS) {
            $victimIdx = 0;
            foreach ($must as $i => $s) {
                if ($master->byId[$s['tag_id']]['count'] < $master->byId[$must[$victimIdx]['tag_id']]['count']) {
                    $victimIdx = $i;
                }
            }
            $victim = $must[$victimIdx];
            array_splice($must, $victimIdx, 1);
            $should[] = [
                'tag_id' => $victim['tag_id'],
                'reason' => $victim['reason'] . '（件数不足のため must から降格）',
                'weight' => self::DEMOTED_WEIGHT,
            ];
            $before = $result['estimate'];
            $result = $backend->count($master, $must, $should, $exclude);
            $steps[] = [
                'demoted_tag_id' => $victim['tag_id'],
                'demoted_tag_name' => $master->byId[$victim['tag_id']]['name'],
                'estimate_before' => $before,
                'estimate_after' => $result['estimate'],
            ];
        }
        return [$must, $should, $steps, $result];
    }

    private static function describe(TagMaster $master, array $sel, bool $withWeight = false): array
    {
        $t = $master->byId[$sel['tag_id']];
        $d = [
            'tag_id' => $t['id'],
            'name' => $t['name'],
            'kind' => $t['kind'],
            'category' => $t['category'],
            'company_count' => $t['count'],
            'reason' => $sel['reason'],
        ];
        if ($withWeight) {
            $d['weight'] = $sel['weight'];
        }
        return $d;
    }

    /** モデル出力から、検証済みの検索条件と件数見積もりを組み立てる。 */
    public static function build(array $raw, TagMaster $master, CountBackend $backend, int $minCount): array
    {
        $warnings = [];
        [$must, $should, $exclude] = self::normalize(is_array($raw['tag_selection'] ?? null) ? $raw['tag_selection'] : [], $master, $warnings);
        if (!$must && !$should) {
            $warnings[] = '有効な must/should タグが1つもありません。商品資料の内容を確認してください。';
        }

        [$must, $should, $steps, $result] = self::relaxUntil($master, $backend, $must, $should, $exclude, $minCount);
        if ($result['estimate'] !== null && $result['estimate'] < $minCount) {
            $warnings[] = sprintf('条件を緩めても推定件数が %s 件に届きませんでした（%s 件）。', number_format($minCount), number_format($result['estimate']));
        }

        $shouldDesc = array_map(fn($s) => self::describe($master, $s, true), $should);
        usort($shouldDesc, fn($a, $b) => $b['weight'] <=> $a['weight']);

        return [
            'must' => array_map(fn($s) => self::describe($master, $s), $must),
            'should' => $shouldDesc,
            'exclude' => array_map(fn($s) => self::describe($master, $s), $exclude),
            'scoring_rule' => self::SCORING_RULE,
            'min_count' => $minCount,
            'estimate' => ['count' => $result['estimate'], 'method' => $result['method']],
            'relaxation_steps' => $steps,
            'warnings' => $warnings,
        ];
    }
}
