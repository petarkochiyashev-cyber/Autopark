<?php
declare(strict_types=1);

/* Business-card reading through the Claude API (official Anthropic PHP SDK). */

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

function ai_enabled(): bool
{
    return trim((string)(config()['anthropic_api_key'] ?? '')) !== '';
}

/**
 * Reads a supplier from pasted text (e-mail signature, web page, message).
 * @param list<array{id:int, name:string, grp:string}> $categories grp is the group's display name
 * @return array<string,mixed>
 */
function read_supplier_text(string $text, array $categories): array
{
    return read_business_card([], $categories, $text);
}

/**
 * Reads a supplier from business-card photos, or from pasted text when $text is given.
 * @param list<array{data:string, type:string}> $images raw image bytes and MIME type
 * @param list<array{id:int, name:string, grp:string}> $categories
 * @return array<string,mixed>
 */
function read_business_card(array $images, array $categories, ?string $text = null): array
{
    require_once APP_ROOT . '/vendor/autoload.php';
    $cfg = config();
    $client = new Client(apiKey: $cfg['anthropic_api_key']);

    $catList = implode("\n", array_map(
        fn($c) => "- {$c['id']}: {$c['name']} ({$c['grp']})",
        $categories
    ));

    $content = [];
    foreach ($images as $img) {
        $content[] = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => $img['type'], 'data' => base64_encode($img['data'])],
        ];
    }
    $rules = "Телефоните и имейлите, ако са няколко, раздели със запетая. "
        . "В products напиши накратко на български какво предлага фирмата, ако личи. "
        . "В categoryIds сложи номерата на подходящите категории от списъка; празен масив, ако не личи. "
        . "Ако някое поле липсва, остави празен низ. Не измисляй данни.\n\nКатегории:\n"
        . ($catList ?: '(няма)');
    if ($text !== null) {
        $content[] = ['type' => 'text', 'text' =>
            "Текстът по-долу съдържа данни за един доставчик: подпис от имейл, визитка, текст от сайт или съобщение. "
            . "Разпредели данните по полетата. " . $rules
            . "\n\nТекст:\n\"\"\"\n" . mb_substr($text, 0, 20000) . "\n\"\"\"",
        ];
    } else {
        $content[] = ['type' => 'text', 'text' =>
            "Снимките по-горе са на визитка на доставчик (лице и евентуално гръб). Разчети текста от визитката; "
            . "той може да е на български, на латиница или на друг език.\n"
            . "Попълни полетата с данните от визитката. " . $rules,
        ];
    }

    $str = ['type' => 'string'];
    $fields = ['company', 'person', 'position', 'phones', 'emails', 'website', 'address', 'city', 'eik', 'products'];
    $schema = [
        'type' => 'object',
        'properties' => array_fill_keys($fields, $str) + ['categoryIds' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        'required' => [...$fields, 'categoryIds'],
        'additionalProperties' => false,
    ];

    try {
        $message = $client->beta->messages->create(
            model: $cfg['anthropic_model'] ?? 'claude-opus-5-5',
            maxTokens: 4000,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
    } catch (AuthenticationException | PermissionDeniedException) {
        throw new RuntimeException('Ключът за Anthropic API е невалиден. Проверете anthropic_api_key в config.php.');
    } catch (RateLimitException) {
        throw new RuntimeException('Твърде много заявки към Claude. Опитайте отново след минута.');
    } catch (BadRequestException $e) {
        $msg = (string)$e->getAPIMessage();
        if (stripos($msg, 'credit') !== false) {
            throw new RuntimeException('Няма достатъчно кредит в профила в Anthropic Console (Plans & Billing).');
        }
        throw new RuntimeException('Claude не прие заявката: ' . $msg);
    } catch (APIStatusException $e) {
        throw new RuntimeException('Claude е временно недостъпен (' . ($e->status ?? '?') . '). Опитайте отново.');
    } catch (APIConnectionException) {
        throw new RuntimeException('Няма връзка с Claude. Опитайте отново.');
    }

    if ($message->stopReason === 'refusal') {
        throw new RuntimeException('Claude отказа да разчете тази снимка. Попълнете данните ръчно.');
    }
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $data = json_decode($block->text, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    throw new RuntimeException('Не успях да разчета визитката. Опитайте с по-ясна снимка.');
}
