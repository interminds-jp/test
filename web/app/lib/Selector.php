<?php
declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

final class SelectionException extends RuntimeException
{
}

/** Claude 呼び出し。プロンプトとスキーマは Python 版と共有（shared/）。 */
final class Selector
{
    public const MAX_TOKENS = 16000;
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    public const REQUEST_TIMEOUT_SEC = 150;

    public static function systemPromptHead(): string
    {
        return self::sharedFile('system_prompt.txt');
    }

    public static function userInstruction(): string
    {
        return self::sharedFile('user_instruction.txt');
    }

    public static function outputSchema(): array
    {
        return json_decode(self::sharedFile('output_schema.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function sharedFile(string $name): string
    {
        $path = Config::sharedDir() . '/' . $name;
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("共有ファイルを読み込めません: {$path}");
        }
        return $text;
    }

    /**
     * @param list<array{kind:string,label:string,data:string}> $sources
     * @return array<string,mixed> messages->create() の名前付き引数（camelCase）
     */
    public static function buildRequest(TagMaster $master, array $sources, string $hint, string $model, string $effort): array
    {
        $content = [];
        foreach ($sources as $s) {
            if ($s['kind'] === 'pdf') {
                $content[] = [
                    'type' => 'document',
                    'title' => $s['label'],
                    'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($s['data'])],
                ];
            }
        }
        foreach ($sources as $s) {
            if ($s['kind'] === 'text') {
                $content[] = ['type' => 'text', 'text' => "<source label=\"{$s['label']}\">\n{$s['data']}\n</source>"];
            }
        }
        $instruction = self::userInstruction();
        if ($hint !== '') {
            $instruction .= "\n\n追加の指示・前提:\n{$hint}";
        }
        $content[] = ['type' => 'text', 'text' => $instruction];

        return [
            'model' => $model,
            'maxTokens' => self::MAX_TOKENS,
            'system' => [[
                'type' => 'text',
                'text' => self::systemPromptHead() . $master->catalogText(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $content]],
            'outputConfig' => [
                'effort' => $effort,
                'format' => ['type' => 'json_schema', 'schema' => self::outputSchema()],
            ],
        ];
    }

    /**
     * Claude を呼んで構造化出力（連想配列）と使用量を返す。
     *
     * @return array{0:array,1:array}
     */
    public static function call(array $request, bool $fallback, ?Client $client = null): array
    {
        $apiKey = (string) Config::get('anthropic_api_key');
        if ($client === null) {
            if ($apiKey === '') {
                throw new SelectionException('API キーが設定されていません（app/config.php の anthropic_api_key か環境変数 ANTHROPIC_API_KEY）。');
            }
            $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => self::REQUEST_TIMEOUT_SEC, 'maxRetries' => 1]);
        }

        try {
            if ($fallback) {
                $message = $client->beta->messages->create(
                    ...$request,
                    betas: [self::FALLBACK_BETA],
                    fallbacks: 'default',
                );
            } else {
                $message = $client->messages->create(...$request);
            }
        } catch (AuthenticationException $e) {
            throw new SelectionException('API キーの認証に失敗しました。設定を確認してください。', 0, $e);
        } catch (PermissionDeniedException $e) {
            throw new SelectionException('この API キーには必要な権限がありません。', 0, $e);
        } catch (NotFoundException $e) {
            throw new SelectionException('指定したモデルが見つかりません。設定の model を確認してください。', 0, $e);
        } catch (RateLimitException $e) {
            throw new SelectionException('API のレート制限に達しました。しばらくしてから再試行してください。', 0, $e);
        } catch (BadRequestException $e) {
            throw new SelectionException('リクエストが不正です: ' . $e->getMessage(), 0, $e);
        } catch (APIStatusException $e) {
            throw new SelectionException('API エラー（' . $e->getCode() . '）: ' . $e->getMessage(), 0, $e);
        } catch (APIConnectionException $e) {
            throw new SelectionException('API に接続できません。サーバーのネットワーク設定を確認してください。', 0, $e);
        }

        return [self::parse($message), self::usage($message)];
    }

    /** stopReason を確認し、構造化出力の JSON を連想配列にして返す。 */
    public static function parse(object $message): array
    {
        if ($message->stopReason === 'refusal') {
            $cat = $message->stopDetails->category ?? null;
            $cat = $cat instanceof \BackedEnum ? $cat->value : $cat;
            throw new SelectionException('モデルが応答を拒否しました（category=' . ($cat ?? 'null') . '）。');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new SelectionException('出力が max_tokens に達して途中で切れました。');
        }
        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        if ($text === null) {
            throw new SelectionException('応答にテキストブロックがありません。');
        }
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SelectionException('応答が JSON として解釈できません: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new SelectionException('応答の JSON がオブジェクトではありません。');
        }
        return $data;
    }

    public static function usage(object $message): array
    {
        $u = $message->usage;
        return [
            'input_tokens' => $u->inputTokens ?? null,
            'output_tokens' => $u->outputTokens ?? null,
            'cache_creation_input_tokens' => $u->cacheCreationInputTokens ?? null,
            'cache_read_input_tokens' => $u->cacheReadInputTokens ?? null,
        ];
    }
}
