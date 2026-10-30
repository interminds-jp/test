<?php
declare(strict_types=1);

final class TagMaster
{
    public const KIND_ORDER = ['field', 'activity', 'intent', 'new_move', 'trend'];

    public const KIND_LABELS = [
        'field' => '分野タグ',
        'activity' => '活動タグ',
        'intent' => 'インテントタグ',
        'new_move' => '新しい動きタグ',
        'trend' => 'トレンドタグ',
    ];

    public const KIND_HINTS = [
        'field' => 'その企業が扱う・提供する商品/サービス領域（取扱品目に相当）',
        'activity' => '展示会出展・補助金・採用・設立年・売上規模・ツール利用などの行動/属性',
        'intent' => 'IT・ものづくり領域に限った関心シグナル（付与企業は計約1万社と少ない）',
        'new_move' => '過去2年は無く直近2ヶ月で新規に出現した動き（営業タイミング）',
        'trend' => 'DX・AI・IoT などのトレンド導入/提供企業',
    ];

    /** @var array<string, array> */
    public array $byId = [];
    /** @var list<array> */
    public array $tags = [];
    public array $meta = [];

    public static function load(?string $path = null): self
    {
        $path ??= Config::dataDir() . '/tags.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("タグマスタを読み込めません: {$path}");
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $m = new self();
        $m->meta = $data['meta'];
        foreach ($data['tags'] as $t) {
            $tag = [
                'id' => (string) $t['id'],
                'kind' => $t['kind'],
                'category' => $t['category'],
                'name' => $t['name'],
                'count' => (int) $t['count'],
                'desc' => $t['desc'],
            ];
            $m->tags[] = $tag;
            $m->byId[$tag['id']] = $tag;
        }
        return $m;
    }

    public function totalCompanies(): int
    {
        return (int) $this->meta['tagged_companies']['marketing_total'];
    }

    public function sourceLabel(): string
    {
        return (string) ($this->meta['source'] ?? '');
    }

    /** モデル出力のタグ番号ゆれ（前後空白・小文字・数値型）を吸収する。 */
    public function resolve($rawId): ?array
    {
        if (!is_string($rawId) && !is_int($rawId)) {
            return null;
        }
        return $this->byId[strtoupper(trim((string) $rawId))] ?? null;
    }

    public function catalogText(): string
    {
        $lines = [];
        foreach (self::KIND_ORDER as $kind) {
            $tags = array_values(array_filter($this->tags, fn($t) => $t['kind'] === $kind));
            $lines[] = sprintf('## %s（%s・%d種）: %s', self::KIND_LABELS[$kind], $kind, count($tags), self::KIND_HINTS[$kind]);
            $lines[] = '番号 | カテゴリー | タグ名 | 社数 | 説明';
            foreach ($tags as $t) {
                $lines[] = sprintf('%s | %s | %s | %d社 | %s', $t['id'], $t['category'], $t['name'], $t['count'], $t['desc']);
            }
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
