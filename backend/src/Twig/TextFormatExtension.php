<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TextFormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('clean_text', [$this, 'cleanText']),
        ];
    }

    /**
     * Clean and format OCR text
     */
    public function cleanText(?string $text): string
    {
        if (!$text) {
            return '';
        }

        // Decode unicode escape sequences if present
        $text = $this->decodeUnicodeSequences($text);

        // Remove multiple spaces/newlines
        $text = preg_replace('/\n\s*\n/', "\n", $text);
        $text = preg_replace('/ +/', ' ', $text);

        // Trim
        $text = trim($text);

        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Decode Unicode escape sequences like \u0441\u0442
     */
    private function decodeUnicodeSequences(string $text): string
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
            return html_entity_decode('&#' . hexdec($matches[1]) . ';', ENT_NOQUOTES, 'UTF-8');
        }, $text);
    }
}
