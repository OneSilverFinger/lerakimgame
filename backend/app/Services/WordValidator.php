<?php

namespace App\Services;

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
            $w = mb_strtoupper(trim($line));
            if ($w !== '') {
                $this->words[$w] = true;
            }
        }
    }

    public function exists(string $word): bool
    {
        return isset($this->words[mb_strtoupper($word)]);
    }
}
