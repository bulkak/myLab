<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use App\Service\Contract\PromptBuilderInterface;

/**
 * Prompt builder optimized for medical document analysis with Russian support.
 *
 * All prompts:
 * - Support Russian medical terminology (витамин B12, фолиевая кислота, хромий, кальций, и т.д.)
 * - Return results with Russian test names as written in the document
 * - Support Russian units (пмоль/л, ммоль/л, г/л, 10^9/л, мкмоль/л, и т.д.)
 * - Include detection of abnormal values (above/below normal ranges)
 */
class MedicalAnalysisPromptBuilder implements PromptBuilderInterface
{
    /**
     * Model-specific prompt optimizations.
     * All prompts emphasize Russian medical terminology recognition.
     *
     * @var array<string, string>
     */
    private array $modelPrompts = [
        'csv' => <<<PROMPT
Твоя задача — распознать ВСЕ строки из ТАБЛИЦ на изображении медицинского анализа.

КРИТИЧЕСКИ ВАЖНО:
- НЕ ПРОПУСКАЙ ни одной строки с числовым значением.
- Если в документе есть два показателя с одинаковым названием (например, "Нейтрофилы сегментоядерные" в % и в x10^9/л) — выведи ОБЕ строки.
- НЕ ОКРУГЛЯЙ числа. Пиши ТОЧНО как видишь: "4.91", а не "4.9"; "140", а не "140.0".
- Если диапазон нормы указан через тире (132-172) — раздели на две колонки: 132 и 172.
- Если единица измерения не указана — оставь колонку пустой.

Выведи в формате CSV с разделителем "|". КОЛОНКИ:
Название | Значение | Единица | Норма_мин | Норма_макс

Пример правильного вывода для фрагмента вашего документа:
Гемоглобин | 140 | г/л | 132 | 172
Эритроциты | 4.91 | x10^12/л | 4.28 | 5.78
Нейтрофилы сегментоядерные | 1.69 | x10^9/л | 1.5 | 6.8
Нейтрофилы сегментоядерные % | 38.30 | % | 37.95 | 71.44
Лимфоциты | 2.20 | x10^9/л | 1.1 | 3.4
Лимфоциты % | 49.9 | % | 24 | 48.4
Креатинин (венозная кровь) | 106.0 | мкмоль/л | 74 | 110
Группа крови | III(B) |  |  | 
Резус-фактор (Rh) | положительный |  |  | 

Теперь распознай ВСЕ строки из ТВОЕГО документа. Не добавляй пояснений, только CSV.
PROMPT,
        'default' => <<<PROMPT
Ты эксперт по анализу медицинских документов. Извлеки данные в JSON.

ГЛАВНОЕ ПРАВИЛО:
В metrics включай ТОЛЬКО те строки, у которых ЕСТЬ числовое значение (или текст: "положительный"/"отрицательный").
Если в строке НЕТ значения (пусто, прочерк, или это просто заголовок раздела) — ПРОПУСТИ эту строку.

Примеры строк, которые НЕ включать:
- "Общий анализ крови с лейкоцитарной формулой..." (нет значения)
- "Биохимические исследования крови" (заголовок)
- "Показатель | Значение | Норма" (шапка таблицы)

Примеры строк, которые ВКЛЮЧАТЬ:
- Гемоглобин | 140 | г/л | 132-172
- Лимфоциты % | 33 | % | 19-37
- Резус-фактор | положительный | null | null

ФОРМАТ JSON:
{
  "title": "Название анализа",
  "analysisDate": "2024-03-20",
  "metrics": [
    {"name": "Гемоглобин", "value": "140", "unit": "г/л", "referenceMin": "132", "referenceMax": "172", "isAboveNormal": false, "isBelowNormal": false}
  ]
}

Правила:
- name — точное название показателя из документа
- value — число или текст (если нет значения — пропустить всю строку)
- unit — единица или null
- referenceMin / referenceMax — из диапазона или null
- isAboveNormal / isBelowNormal — true только если есть ↑/↓/H/L

Извлеки ВСЕ показатели с числовыми значениями. Ничего не добавляй от себя.
PROMPT,
    ];

    public function buildMedicalAnalysisPrompt(?string $modelName = null): string
    {
        $modelKey = $this->detectModelKey($modelName);
        return $this->modelPrompts[$modelKey] ?? $this->modelPrompts['default'];
    }


    public function getSupportedModels(): array
    {
        return [
            'GigaChat-Pro',
            'GigaChat-Max',
            'gpt-4o',
            'qwen3.5-35b-a3b-fp8/latest',
        ];
    }

    public function supportsModel(string $modelName): bool
    {
        foreach ($this->getSupportedModels() as $supported) {
            if (str_starts_with($modelName, explode(':', $supported)[0])) {
                return true;
            }
        }
        return true; // Default prompts work for all models
    }

    /**
     * Detect the model key from model name for prompt selection.
     */
    private function detectModelKey(?string $modelName): string
    {
        if ($modelName && (str_contains(strtolower($modelName), 'gigachat') || str_contains(strtolower($modelName), 'gpt') || str_contains(strtolower($modelName), 'qwen'))) {
            return 'csv';
        }
        return 'default';
    }
}
