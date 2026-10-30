<?php
declare(strict_types=1);

/** 入力 → Claude → 検索条件、までを束ねる（API・テストの共通入口）。 */
final class Analyzer
{
    private const PRODUCT_DEFAULTS = ['name' => '(不明)', 'summary' => '', 'category' => '', 'buyer_problems' => []];
    private const HYPOTHESIS_DEFAULTS = [
        'sell_mode' => 'sell_to_users',
        'industries' => [],
        'job_titles' => [],
        'company_size' => '',
        'handled_items' => [],
        'keywords' => [],
    ];

    /**
     * @param list<array{kind:string,label:string,data:string}> $sources
     * @param array{mock?:bool,client?:?\Anthropic\Client,backend?:?CountBackend} $options
     */
    public static function run(array $sources, string $hint, array $warnings, array $options = []): array
    {
        $master = TagMaster::load();
        $model = (string) Config::get('model');
        $effort = (string) Config::get('effort');
        $usage = null;
        $mock = !empty($options['mock']);

        if ($mock) {
            $raw = json_decode(
                (string) file_get_contents(Config::exampleDir() . '/sample_model_output.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $modelLabel = '(mock) sample_model_output.json';
        } else {
            $request = Selector::buildRequest($master, $sources, $hint, $model, $effort);
            [$raw, $usage] = Selector::call($request, (bool) Config::get('use_fallback'), $options['client'] ?? null);
            $modelLabel = $model;
        }

        $raw['product'] = array_merge(self::PRODUCT_DEFAULTS, is_array($raw['product'] ?? null) ? $raw['product'] : []);
        $raw['target_hypothesis'] = array_merge(self::HYPOTHESIS_DEFAULTS, is_array($raw['target_hypothesis'] ?? null) ? $raw['target_hypothesis'] : []);

        $backend = $options['backend'] ?? new MockCountBackend();
        $query = Query::build($raw, $master, $backend, (int) Config::get('min_count'));

        return [
            'meta' => [
                'model' => $modelLabel,
                'mock' => $mock,
                'tag_master' => $master->sourceLabel(),
                'generated_at' => date('c'),
                'inputs' => array_map(fn($s) => $s['label'], $sources),
                'warnings' => $warnings,
            ],
            'product' => $raw['product'],
            'target_hypothesis' => $raw['target_hypothesis'],
            'query' => $query,
            'strategy_notes' => (string) ($raw['strategy_notes'] ?? ''),
            'caveats' => is_array($raw['caveats'] ?? null) ? $raw['caveats'] : [],
            'usage' => $usage,
        ];
    }
}
