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
    private const ROUND_SECONDS = 100;

    private array $vowels = ['А','О','И','Е','Ё','Э','Ы','У','Я','Ю'];
    private array $consonants = [
        'Б','В','Г','Д','Ж','З','Й','К','Л','М','Н','П','Р','С','Т','Ф','Х','Ц','Ч','Ш','Щ',
        'Ь','Ъ',
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
    }

    public function start(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $this->resetDailyFreeSwaps($user);

        $letters = $this->generateLetters();

        $session = GameSession::create([
            'user_id' => $user->id,
            'letters' => $letters,
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

        $newLetters = $this->generateLetters();
        // стараемся избежать той же раздачи
        $tries = 0;
        while ($newLetters === $session->letters && $tries < 5) {
            $newLetters = $this->generateLetters();
            $tries++;
        }
        $session->letters = $newLetters;
        $session->swaps_used += 1;
        $session->save();

        return response()->json([
            'letters' => $this->lettersToArray($session->letters),
            'free_swaps_left' => $user->free_swaps_left,
            'gems' => $user->gems,
            'hint_words' => [],
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

    private function preset(): array
    {
        return $this->presets[array_rand($this->presets)];
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
            if (mb_strlen($word) < 2) {
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
        if ($this->validator->exists($word)) {
            return true;
        }
        return in_array($word, $this->dictionary, true);
    }

    private function generateLetters(int $length = 6): string
    {
        // гарантируем минимум 2 гласные
        $letters = [];
        shuffle($this->vowels);
        shuffle($this->consonants);
        $letters[] = $this->vowels[array_rand($this->vowels)];
        $letters[] = $this->vowels[array_rand($this->vowels)];

        $pool = array_merge($this->vowels, $this->consonants, $this->consonants); // чуть больше согласных
        for ($i = 2; $i < $length; $i++) {
            $letters[] = $pool[array_rand($pool)];
        }

        shuffle($letters);
        return implode('', $letters);
    }
}
