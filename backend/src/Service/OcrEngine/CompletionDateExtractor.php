<?php

declare(strict_types=1);

namespace App\Service\OcrEngine;

/**
 * Достаёт дату анализа из ответа chat/completions.
 * У моделей с отдельным полем рассуждений (reasoning_content) ответ часто обрезается по max_tokens,
 * а content остаётся пустым — тогда парсим дату из текста рассуждений.
 */
final class CompletionDateExtractor
{
    /**
     * Промпт для второго шага (дата): короткий финальный ответ снижает расход токенов, но лимит всё равно держим запасом.
     */
    public const PROMPT_DATE_RU = <<<'TXT'
Найди на изображении дату исследования (дата взятия пробы / дата анализа, часто подпись «Дата исследования» у строк таблицы).
Ответь одной строкой: только дата в формате YYYY-MM-DD. Без рассуждений, без пояснений, без другого текста.
Если дат несколько — верни дату, относящуюся к первой строке с результатами анализа в таблице.
Пример ответа: 2024-03-20
TXT;

    /** Запас для моделей с reasoning: иначе content остаётся null при finish_reason=length. */
    public const DATE_STEP_MAX_TOKENS = 1024;

    /**
     * @param array<string, mixed> $response
     */
    public static function fromChatCompletionResponse(array $response): ?string
    {
        $choice = $response['choices'][0] ?? null;
        if (!\is_array($choice)) {
            return null;
        }
        $msg = $choice['message'] ?? [];
        if (!\is_array($msg)) {
            return null;
        }

        $parts = [];
        foreach (['content', 'reasoning_content', 'reasoning', 'thought'] as $key) {
            $v = $msg[$key] ?? null;
            if (\is_string($v)) {
                $t = self::cleanAssistantDateFragment($v);
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
        }

        foreach ($parts as $text) {
            $parsed = self::parseDateNearInvestigationLabel($text);
            if ($parsed !== null) {
                return $parsed;
            }
            $parsed = self::parseFirstDate($text);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Чистит поле message.content (и при необходимости похожий мусор): trim, переводы строк → пробел,
     * снятие markdown-обёртки ```…``` или `…` вокруг одной строки.
     */
    private static function cleanAssistantDateFragment(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return '';
        }
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);
        if (preg_match('/^`{1,3}\s*(.+?)\s*`{1,3}$/u', $t, $m)) {
            $t = trim($m[1]);
            $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
            $t = trim($t);
        }

        return $t;
    }

    /**
     * В рассуждениях модель часто перечисляет даты; первая в тексте может быть «лицензия», а не анализ.
     * Приоритет — строка, где есть «исследован» (как на бланке «Дата исследования»).
     */
    private static function parseDateNearInvestigationLabel(string $text): ?string
    {
        $lines = preg_split("/\r\n|\n|\r/u", $text);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            $lower = mb_strtolower($line);
            if (!str_contains($lower, 'исследован')) {
                continue;
            }
            if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $line, $m)) {
                $d = (int) $m[1];
                $mo = (int) $m[2];
                $y = (int) $m[3];
                if (self::isValidYmd($y, $mo, $d)) {
                    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
                }
            }
            if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $line, $m)) {
                $y = (int) $m[1];
                $mo = (int) $m[2];
                $d = (int) $m[3];
                if (self::isValidYmd($y, $mo, $d)) {
                    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
                }
            }
        }

        return null;
    }

    public static function parseFirstDate(string $text): ?string
    {
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $text, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if (self::isValidYmd($y, $mo, $d)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $text, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if (self::isValidYmd($y, $mo, $d)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        return null;
    }

    private static function isValidYmd(int $y, int $mo, int $d): bool
    {
        if ($y < 1990 || $y > 2100) {
            return false;
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return false;
        }

        return checkdate($mo, $d, $y);
    }
}
