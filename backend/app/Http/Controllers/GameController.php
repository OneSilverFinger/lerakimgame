<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\User;
use App\Services\WordValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GameController extends Controller
{
    private const SWAP_COST = 200;
    private const HINT_COST = 100;
    private const ROUND_SECONDS = 100;
    private array $presetWordSet;

    /**
     * Подборки букв, из которых гарантированно собираются несколько слов.
     * Держим достаточный пул, чтобы замены не повторялись сразу.
     */
    private array $presets = [
        ['letters' => 'СТЕКЛО', 'sample_words' => ['СТОЛ', 'ЛЕС', 'СЕТ', 'ЛОТ', 'КОЛ', 'ТЕСЛО']],
        ['letters' => 'РАДИУС', 'sample_words' => ['РАД', 'СУД', 'РИС', 'ДУРА', 'УДАР', 'РАДИУС']],
        ['letters' => 'ПАРТОК', 'sample_words' => ['ПАР', 'ТОП', 'РОТ', 'ТОР', 'ПОТ', 'ПОРТ']],
        ['letters' => 'ЛАМПАД', 'sample_words' => ['ЛАД', 'ПАЛ', 'ПЛАВ', 'ЛАМПА', 'ПЛАВДА']],
        ['letters' => 'ГРУШАН', 'sample_words' => ['ГРА', 'ГРУША', 'ШАР', 'РУГА', 'ГАРН']],
        ['letters' => 'ПЕСЧАН', 'sample_words' => ['ПЕС', 'ПАН', 'САН', 'ЧАН', 'ПЕСЧАН']],
        ['letters' => 'МОДЕЛЬ', 'sample_words' => ['МОДА', 'ДЕЛО', 'ЛЕД', 'МЕД', 'МОДЕЛЬ']],
        ['letters' => 'ПРИМОР', 'sample_words' => ['ПРИМ', 'РИМ', 'МОР', 'ПИР', 'ПРИМОР']],
        ['letters' => 'ГОЛУБЯ', 'sample_words' => ['ГОЛ', 'ЛУГ', 'БЫЛ', 'ГОЛУБЬ', 'БЛЮДО']],
        ['letters' => 'КЛЕВЕР', 'sample_words' => ['КЛЕВ', 'ВЕК', 'РЕВ', 'КЛЕВЕР']],
    ];

    /**
     * Очень короткий встроенный словарь, чтобы отсеивать несуществующие слова.
     * В проде можно заменить на внешнюю базу/словари.
     */
    private array $dictionary = [
        'СТОЛ','ЛЕС','СЕТ','ЛОТ','КОЛ','ТЕСЛО','СЕЛО','СТОК','СОЛО','КОЛЕ','СЕКТ','ЛЕСТО',
        'РАД','ДАР','СУД','РИС','ДУРА','ДАРЫ','СИР','САД','УДАР','РАДУС','РУДИ','АРДУС',
        'ПАР','РОТ','ТОР','ПОТ','КОРТ','ПОРТ','ТОП','ПАРК','КРОТ','ТРОП',
        'ДОМ','МОРЕ','НОС','СОН','ЛИС','СИЛА','ЛИСТ','СЛОН','ТАРА','РЯД','МЯЧ','КОТ','ТОК',
        'СОЛЬ','МЕЛ','МЕЛО','СОЛЕ','ЛОМ','МОСТ','СТОМ','ЛЕН','ЛЕНТ','ТЕЛО','ТЕЛ','КЛЁВ',
    ];

    public function __construct(private WordValidator $validator)
    {
        // Быстрый набор слов из подсказок пресетов, чтобы они всегда считались валидными,
        // даже если внешний словарь не подгрузился.
        $this->presetWordSet = [];
        foreach ($this->presets as $preset) {
            foreach ($preset['sample_words'] as $w) {
                $this->presetWordSet[mb_strtoupper($w)] = true;
            }
        }
    }

    public function start(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $this->resetDailyFreeSwaps($user);

        $preset = $this->preset();
        $letters = $preset['letters'];

        $session = GameSession::create([
            'user_id' => $user->id,
            'letters' => $letters,
            'hints_revealed' => false,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'letters' => $this->lettersToArray($letters),
            'free_swaps_left' => $user->free_swaps_left,
            'gems' => $user->gems,
            'round_seconds' => self::ROUND_SECONDS,
            'hint_words' => [],
        ]);
    }

    public function swap(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:game_sessions,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $session = GameSession::where('id', $data['session_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->resetDailyFreeSwaps($user);

        if ($session->completed_at) {
            throw ValidationException::withMessages([
                'session_id' => ['Сессия уже завершена'],
            ]);
        }

        if ($user->free_swaps_left <= 0) {
            throw ValidationException::withMessages([
                'swap' => ['Нет доступных замен. Купите в магазине.'],
            ]);
        }
        $user->free_swaps_left -= 1;
        $user->save();

        $preset = $this->preset(exclude: $session->letters);
        $session->letters = $preset['letters'];
        $session->swaps_used += 1;
        $session->save();

        return response()->json([
            'letters' => $this->lettersToArray($session->letters),
            'free_swaps_left' => $user->free_swaps_left,
            'gems' => $user->gems,
            'hint_words' => $session->hints_revealed ? $preset['sample_words'] : [],
        ]);
    }

    public function revealHints(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:game_sessions,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $session = GameSession::where('id', $data['session_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($session->completed_at) {
            throw ValidationException::withMessages([
                'session_id' => ['Сессия уже завершена'],
            ]);
        }

        if (!$session->hints_revealed) {
            if ($user->gems < self::HINT_COST) {
                throw ValidationException::withMessages([
                    'gems' => ['Недостаточно самоцветов (нужно 100)'],
                ]);
            }
            $user->gems -= self::HINT_COST;
            $user->save();
            $session->hints_revealed = true;
            $session->save();
        }

        $preset = $this->presetForLetters($session->letters);

        return response()->json([
            'hint_words' => $preset['sample_words'],
            'gems' => $user->gems,
            'free_swaps_left' => $user->free_swaps_left,
        ]);
    }

    public function submit(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:game_sessions,id'],
            'words' => ['array'],
            'words.*' => ['string', 'min:2', 'max:20'],
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:'.self::ROUND_SECONDS],
        ]);

        /** @var User $user */
        $user = $request->user();
        $session = GameSession::where('id', $data['session_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($session->completed_at) {
            return response()->json([
                'score' => $session->score,
                'gems_earned' => $session->gems_earned,
                'letters' => $this->lettersToArray($session->letters),
            ]);
        }

        $words = $this->sanitizeWords($session->letters, $data['words'] ?? []);
        $score = $this->calculateScore($words);
        $gems = $this->calculateGems($words);

        $session->update([
            'words' => $words,
            'score' => $score,
            'gems_earned' => $gems,
            'duration_seconds' => $data['duration_seconds'],
            'completed_at' => now(),
        ]);

        $user->gems += $gems;
        $user->total_gems += $gems;
        $user->total_games += 1;
        $user->best_score = max($user->best_score, $score);
        $user->save();

        return response()->json([
            'score' => $score,
            'gems_earned' => $gems,
            'gems_total' => $user->gems,
            'free_swaps_left' => $user->free_swaps_left,
        ]);
    }

    private function resetDailyFreeSwaps(User $user): void
    {
        $today = now()->toDateString();
        if ($user->last_free_reset_at !== $today && $user->free_swaps_left < 3) {
            $user->free_swaps_left = 3;
            $user->last_free_reset_at = now()->toDateString();
            $user->save();
        }
    }

    public function checkWord(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:game_sessions,id'],
            'word' => ['required', 'string', 'min:2', 'max:20'],
        ]);

        $user = $request->user();
        $session = GameSession::where('id', $data['session_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $lettersBag = $this->letterBag($session->letters);
        $word = mb_strtoupper(trim($data['word']));

        if (!$this->isInDictionary($word) || !$this->canBuildFromLetters($lettersBag, $word)) {
            throw ValidationException::withMessages([
                'word' => ['Слово не подходит или нет в словаре'],
            ]);
        }

        return response()->json([
            'word' => $word,
        ]);
    }

    private function lettersToArray(string $letters): array
    {
        return preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY);
    }

    private function sanitizeWords(string $letters, array $words): array
    {
        $lettersBag = $this->letterBag($letters);
        $valid = [];

        foreach (array_unique($words) as $word) {
            $word = mb_strtoupper(trim($word));
            if (mb_strlen($word) < 3) {
                continue;
            }
            if ($this->isInDictionary($word) && $this->canBuildFromLetters($lettersBag, $word)) {
                $valid[] = $word;
            }
        }

        return $valid;
    }

    private function calculateScore(array $words): int
    {
        $score = 0;
        foreach ($words as $word) {
            $score += mb_strlen($word) * 50;
        }
        return $score;
    }

    private function calculateGems(array $words): int
    {
        $gems = 0;
        foreach ($words as $word) {
            $gems += mb_strlen($word); // 1 буква = 1 самоцвет
        }
        return max(0, $gems);
    }

    private function letterBag(string $letters): array
    {
        $bag = [];
        foreach ($this->lettersToArray($letters) as $letter) {
            $bag[$letter] = ($bag[$letter] ?? 0) + 1;
        }
        return $bag;
    }

    private function canBuildFromLetters(array $bag, string $word): bool
    {
        $local = $bag;
        foreach ($this->lettersToArray($word) as $char) {
            if (($local[$char] ?? 0) === 0) {
                return false;
            }
            $local[$char]--;
        }
        return true;
    }

    private function isInDictionary(string $word): bool
    {
        if (isset($this->presetWordSet[$word])) {
            return true;
        }
        if ($this->validator->exists($word)) {
            return true;
        }
        return in_array($word, $this->dictionary, true);
    }

    private function preset(?string $exclude = null): array
    {
        $pool = $this->presets;
        if ($exclude) {
            $pool = array_values(array_filter($pool, fn($p) => $p['letters'] !== $exclude));
        }
        return $pool[array_rand($pool)];
    }

    private function presetForLetters(string $letters): array
    {
        foreach ($this->presets as $preset) {
            if ($preset['letters'] === $letters) {
                return $preset;
            }
        }
        return ['letters' => $letters, 'sample_words' => []];
    }
}
