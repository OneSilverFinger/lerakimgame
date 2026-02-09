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
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get("https://api.dictionaryapi.dev/api/v2/entries/ru/{$upper}");

            if ($response->successful()) {
                $json = $response->json();
                return is_array($json) && count($json) > 0;
            }
        } catch (\Throwable $e) {
            // Игнорируем сетевые ошибки, полагаемся на локальный словарь
        }

        return false;
    }
}
