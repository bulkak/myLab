# Растеризация PDF → PNG (диагностика и обходы)

Если в `{UPLOAD_DIR}/debug/debug_{analysisId}_page_*.png` **нет чисел** в колонке значений, а в просмотрщике PDF они есть, проблема на этапе **конвертации в растр** (до OCR).

## Диагностика на проблемном файле

### Шрифты и метаданные (Poppler)

```bash
pdffonts problem.pdf
pdfinfo problem.pdf
```

Смотрите колонку **emb** (embedded): не встроенный шрифт для цифр часто даёт расхождение «вьюер рисует, Poppler нет».

### Слои (OCG)

Откройте PDF в Acrobat (или другом вьюере со списком **Layers**). Если результаты на отдельном слое, конвертер может его не рисовать. Обход: **печать в новый PDF** или экспорт без слоёв.

### Сравнение crop box и media box

```bash
pdftoppm -png -r 250 -cropbox problem.pdf out-crop
pdftoppm -png -r 250 problem.pdf out-media
```

Если цифры есть только во втором варианте — отключите crop box в конфиге (`pdf_raster_use_cropbox: false` в `config/services.yaml`).

### Другой движок Poppler

```bash
pdftocairo -png -r 250 problem.pdf out-cairo
```

Если здесь цифры появляются — переключите движок на `pdftocairo` или оставьте fallback (см. ниже).

## Настройка приложения

Параметры в [`config/services.yaml`](../config/services.yaml) (секция `App\Service\Contract\DocumentConverterInterface`):

| Параметр | Смысл |
|----------|--------|
| `pdf_raster_use_cropbox` | `true` — `-cropbox` у Poppler; `false` — media box (часто нужно для узкого crop box у бланков). |
| `pdf_raster_engine` | `pdftoppm` (Splash) или `pdftocairo` (Cairo). |
| `pdf_raster_fallback_engine` | Запасной движок при ошибке или пустом выводе: `pdftocairo`, `pdftoppm`, `ghostscript` (алиас `gs`) или `null` / `~` в YAML (без fallback). Требуется пакет `ghostscript` в образе. |

Перезапустите workers/php после изменения.

## Обход для пользователей

Если исходный файл от лаборатории стабильно даёт пустые значения в PNG, а **«Печать в PDF»** из того же просмотрщика или сохранение копии **без слоёв** исправляет картинку — загружайте в систему **эту перепечатанную** копию до появления автоматического исправления на стороне сервера.
