<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WordValidator
{
    private array $words;

    public function __construct()
    {
        $path = 'dicts_ru.txt';
        if (!Storage::disk('local')->exists($path)) {
            $this->words = [];
            return;
        }
        $content = Storage::disk('local')->get($path);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $this->words = [];
        foreach ($lines as $line) {
            if (str_contains($line, ' ')) {
                [$w] = explode(' ', $line, 2); // частотный список вида "слово 123"
            } else {
                $w = $line;
            }
            $w = mb_strtoupper(trim($w));
            if ($w !== '') {
                $this->words[$w] = true;
            }
        }
    }

    public function exists(string $word): bool
    {
        $upper = mb_strtoupper($word);

        if (isset($this->words[$upper])) {
            return true;
        }

        // Кешируем результат внешней проверки (положительный/отрицательный) на 7 дней
        return Cache::remember("dict:ru:{$upper}", now()->addDays(7), function () use ($upper) {
            return $this->checkExternalDictionary($upper);
        });
    }

    /**
     * Запрос к бесплатному API dictionaryapi.dev (данные из Wiktionary).
     * Возвращает true, если слово найдено, иначе false.
     */
    private function checkExternalDictionary(string $upper): bool
    {
        // 1) dictionaryapi.dev
        try {
            $slug = rawurlencode(mb_strtolower($upper)); // API ожидает нижний регистр

            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'WordRush/1.0 (+https://lerakimgame.ru)'])
                ->get("https://api.dictionaryapi.dev/api/v2/entries/ru/{$slug}");

            if ($response->successful()) {
                $json = $response->json();
                if (is_array($json) && count($json) > 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Игнорируем и пробуем следующий источник
        }

        // 2) ru.wiktionary.org API (MediaWiki). Если страницы нет — вернёт "missing".
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'WordRush/1.0 (+https://lerakimgame.ru)'])
                ->get('https://ru.wiktionary.org/w/api.php', [
                    'action' => 'query',
                    'titles' => mb_strtolower($upper),
                    'format' => 'json',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $pages = $data['query']['pages'] ?? [];
                foreach ($pages as $page) {
                    if (!isset($page['missing'])) {
                        return true; // страница существует
                    }
                }
            }
        } catch (\Throwable) {
            // Игнорируем сетевые ошибки
        }

        return false;
    }
}
