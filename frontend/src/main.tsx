import React, { useEffect, useMemo, useState } from 'react';
import ReactDOM from 'react-dom/client';
import axios from 'axios';
import { DndContext, PointerSensor, closestCenter, useDroppable, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, horizontalListSortingStrategy, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import clsx from 'clsx';
import './style.css';

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8080/api';

const api = axios.create({
  baseURL: API_URL,
});

type User = {
  id: number;
  username: string;
  name: string;
  gems: number;
  free_swaps_left: number;
  best_score: number;
  total_gems: number;
  total_games: number;
};

type Leader = {
  id: number;
  username: string;
  best_score: number;
  total_gems: number;
  total_games: number;
};

type StartResponse = {
  session_id: number;
  letters: string[];
  free_swaps_left: number;
  gems: number;
  round_seconds: number;
  hint_words: string[];
};

type SubmitResponse = {
  score: number;
  gems_earned: number;
  gems_total: number;
  free_swaps_left: number;
};

type LetterTile = { id: string; value: string; lane: 'rack' | 'word' };

type SwapResponse = {
  letters: string[];
  free_swaps_left: number;
  gems: number;
  hint_words?: string[];
};

type ShopResponse = {
  gems: number;
  free_swaps_left: number;
};

function useAuth() {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem('token'));
  const [user, setUser] = useState<User | null>(null);
  const [authLoading, setAuthLoading] = useState(false);

  useEffect(() => {
    if (!token) return;
    api.defaults.headers.common.Authorization = `Bearer ${token}`;
    setAuthLoading(true);
    api
      .get<User>('/me')
      .then((res) => setUser(res.data))
      .catch(() => setToken(null))
      .finally(() => setAuthLoading(false));
  }, [token]);

  const login = async (username: string, password: string) => {
    const res = await api.post<{ token: string; user: User }>('/login', { username, password });
    localStorage.setItem('token', res.data.token);
    api.defaults.headers.common.Authorization = `Bearer ${res.data.token}`;
    setToken(res.data.token);
    setUser(res.data.user);
  };

  const register = async (username: string, password: string) => {
    const res = await api.post<{ token: string; user: User }>('/register', { username, password });
    localStorage.setItem('token', res.data.token);
    api.defaults.headers.common.Authorization = `Bearer ${res.data.token}`;
    setToken(res.data.token);
    setUser(res.data.user);
  };

  const logout = () => {
    localStorage.removeItem('token');
    setToken(null);
    setUser(null);
  };

  return { token, user, authLoading, login, register, logout, setUser };
}

function LetterChip({ letter, active, onClick }: { letter: string; active?: boolean; onClick?: () => void }) {
  return (
    <button
      className={clsx('letter-chip', active && 'letter-chip-active')}
      onClick={onClick}
      type="button"
    >
      {letter}
    </button>
  );
}

function SortableLetter({ letter }: { letter: LetterTile }) {
  const { attributes, listeners, setNodeRef, transform, transition } = useSortable({ id: letter.id });
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  };
  return (
    <div ref={setNodeRef} style={style} {...attributes} {...listeners}>
      <LetterChip letter={letter.value} />
    </div>
  );
}

function DroppableZone({
  id,
  title,
  children,
  highlight,
}: {
  id: string;
  title: string;
  children: React.ReactNode;
  highlight?: boolean;
}) {
  const { setNodeRef, isOver } = useDroppable({
    id,
  });

  return (
    <div ref={setNodeRef} className={clsx('zone', highlight && 'word-zone', isOver && 'zone-over')}>
      <div className="zone-title">{title}</div>
      {children}
    </div>
  );
}

function App() {
  const { user, authLoading, login, register, logout, setUser } = useAuth();

  const [form, setForm] = useState({ username: 'demo', password: 'password', mode: 'login' as 'login' | 'register' });
  const [letters, setLetters] = useState<LetterTile[]>([]);
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [words, setWords] = useState<string[]>([]);
  const [timer, setTimer] = useState(100);
  const [status, setStatus] = useState<'idle' | 'playing' | 'finished'>('idle');
  const [freeSwaps, setFreeSwaps] = useState(3);
  const [banner, setBanner] = useState<string | null>(null);
  const [leaderboard, setLeaderboard] = useState<Leader[]>([]);
  const [loading, setLoading] = useState(false);
  const [hints, setHints] = useState<string[]>([]);
  const [shopLoading, setShopLoading] = useState(false);

  const sensors = useSensors(useSensor(PointerSensor));

  // sync local counters when профиль загрузился
  useEffect(() => {
    if (user) {
      setFreeSwaps(user.free_swaps_left);
    }
  }, [user]);

  useEffect(() => {
    let interval: number | undefined;
    if (status === 'playing') {
      interval = window.setInterval(() => {
        setTimer((t) => {
          if (t <= 1) {
            clearInterval(interval);
            finishGame();
            return 0;
          }
          return t - 1;
        });
      }, 1000);
    }
    return () => clearInterval(interval);
  }, [status]);

  useEffect(() => {
    if (status === 'finished') {
      loadLeaderboard();
    }
  }, [status]);

  useEffect(() => {
    if (user) {
      loadLeaderboard();
    }
  }, [user]);

  const startGame = async () => {
    if (!user) return;
    setLoading(true);
    try {
      const res = await api.post<StartResponse>('/game/start');
      setSessionId(res.data.session_id);
      setLetters(res.data.letters.map((l, idx) => ({ id: `${Date.now()}-${idx}`, value: l, lane: 'rack' })));
      setFreeSwaps(res.data.free_swaps_left);
      setWords([]);
      setTimer(res.data.round_seconds);
      setStatus('playing');
      setBanner(null);
      setHints(res.data.hint_words ?? []);
    } finally {
      setLoading(false);
    }
  };

  const shuffleLetters = async () => {
    if (!sessionId) return;
    setLoading(true);
    try {
      const res = await api.post<SwapResponse>('/game/swap', {
        session_id: sessionId,
      });
      setLetters(res.data.letters.map((l, idx) => ({ id: `${Date.now()}-${idx}`, value: l, lane: 'rack' })));
      setFreeSwaps(res.data.free_swaps_left);
      if (user) setUser({ ...user, gems: res.data.gems });
      setHints(res.data.hint_words ?? []);
    } catch (e: any) {
      setBanner(e?.response?.data?.message ?? 'Не удалось заменить буквы');
    } finally {
      setLoading(false);
    }
  };

  const buySwap = async (pack: number) => {
    setShopLoading(true);
    try {
      const res = await api.post<ShopResponse>('/shop/buy-swap', { pack });
      setFreeSwaps(res.data.free_swaps_left);
      if (user) setUser({ ...user, gems: res.data.gems });
      setBanner(`Куплено замен: ${pack}`);
    } catch (e: any) {
      setBanner(e?.response?.data?.message ?? 'Покупка не удалась');
    } finally {
      setShopLoading(false);
    }
  };

  const finishGame = async () => {
    if (!sessionId || !user) return;
    setStatus('finished');
    setLoading(true);
    try {
      const res = await api.post<SubmitResponse>('/game/submit', {
        session_id: sessionId,
        words,
        duration_seconds: 100 - timer,
      });
      setBanner(`+${res.data.gems_earned} самоцветов, счёт ${res.data.score}`);
      setFreeSwaps(res.data.free_swaps_left);
      setUser({ ...user, gems: res.data.gems_total });
    } finally {
      setLoading(false);
    }
  };

  const backspace = () => {
    const wordLetters = letters.filter((l) => l.lane === 'word');
    if (wordLetters.length === 0) return;
    const last = wordLetters[wordLetters.length - 1];
    setLetters((prev) =>
      prev.map((l) => (l.id === last.id ? { ...l, lane: 'rack' } : l))
    );
  };

  const saveWord = async () => {
    const current = letters
      .filter((l) => l.lane === 'word')
      .map((l) => l.value)
      .join('');
    if (current.length < 2 || !sessionId) return;
    try {
      setLoading(true);
      await api.post('/game/check-word', { session_id: sessionId, word: current });
      setWords((prev) => Array.from(new Set([...prev, current])));
      setBanner(`Добавлено: ${current}`);
      // send letters back to rack
      setLetters((prev) => prev.map((l) => ({ ...l, lane: 'rack' })));
    } catch (e: any) {
      setBanner(e?.response?.data?.message ?? 'Слово не найдено в словаре');
    } finally {
      setLoading(false);
    }
  };

  const resetWord = () => {
    setLetters((prev) => prev.map((l) => ({ ...l, lane: 'rack' })));
  };

  const onDragEnd = (event: any) => {
    const { active, over } = event;
    if (!over) return;

    const activeLetter = letters.find((l) => l.id === active.id);
    if (!activeLetter) return;

    const rackIds = letters.filter((l) => l.lane === 'rack').map((l) => l.id);
    const wordIds = letters.filter((l) => l.lane === 'word').map((l) => l.id);

    const moveToLane = (lane: 'rack' | 'word') =>
      setLetters((prev) =>
        prev.map((l) => (l.id === active.id ? { ...l, lane } : l))
      );

    if (over.id === 'rack') {
      moveToLane('rack');
      return;
    }
    if (over.id === 'word') {
      moveToLane('word');
      return;
    }

    // dropped on another letter
    const overLetter = letters.find((l) => l.id === over.id);
    if (!overLetter) return;
    const targetLane = overLetter.lane;

    if (activeLetter.lane !== targetLane) {
      moveToLane(targetLane);
    }

    const laneIds = targetLane === 'rack' ? rackIds : wordIds;
    const oldIndex = laneIds.indexOf(active.id);
    const newIndex = laneIds.indexOf(over.id);
    if (oldIndex >= 0 && newIndex >= 0) {
      const reorderedIds = arrayMove(laneIds, oldIndex, newIndex);
      setLetters((prev) => {
        const laneItems = prev.filter((l) => l.lane === targetLane);
        const otherItems = prev.filter((l) => l.lane !== targetLane);
        const reordered = reorderedIds
          .map((id) => laneItems.find((l) => l.id === id))
          .filter(Boolean) as LetterTile[];
        return targetLane === 'rack' ? [...reordered, ...otherItems] : [...otherItems, ...reordered];
      });
    }
  };

  const timePercent = useMemo(() => Math.max(0, Math.min(100, (timer / 100) * 100)), [timer]);

  const rackLetters = letters.filter((l) => l.lane === 'rack');
  const wordLetters = letters.filter((l) => l.lane === 'word');

  if (authLoading) {
    return <div className="screen">Загрузка...</div>;
  }

  if (!user) {
    return (
      <div className="screen auth-screen">
        <div className="card">
          <h1>WordRush</h1>
          <p className="muted">Регистрация без почты. Придумайте логин и пароль.</p>
          <label className="field">
            Логин
            <input
              value={form.username}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
              placeholder="nickname"
            />
          </label>
          <label className="field">
            Пароль
            <input
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              placeholder="••••••"
            />
          </label>
          <div className="switcher">
            <button
              className={clsx('pill', form.mode === 'login' && 'pill-active')}
              onClick={() => setForm({ ...form, mode: 'login' })}
            >
              Вход
            </button>
            <button
              className={clsx('pill', form.mode === 'register' && 'pill-active')}
              onClick={() => setForm({ ...form, mode: 'register' })}
            >
              Регистрация
            </button>
          </div>
          <button
            className="primary"
            onClick={() =>
              form.mode === 'login'
                ? login(form.username, form.password)
                : register(form.username, form.password)
            }
          >
            {form.mode === 'login' ? 'Войти' : 'Создать аккаунт'}
          </button>
          <div className="muted">Демо: demo / password</div>
        </div>
      </div>
    );
  }

  return (
    <div className="screen">
      <header className="topbar">
        <div>
          <div className="hello">Привет, {user.name ?? user.username}</div>
          <div className="muted">Самоцветов: {user.gems}</div>
        </div>
        <div className="top-actions">
          <div className="pill small">Замен: {freeSwaps}</div>
        <button className="ghost" onClick={logout}>
          Выйти
        </button>
      </div>
      </header>

      <main className="layout">
        {status !== 'playing' && (
          <>
            <section className="card gradient">
              <div className="section-title">
                <div>
                  <div className="eyebrow">Главное меню</div>
                  <h2>Соберите максимум слов</h2>
                </div>
                <div className="timer">
                  <span>100s</span>
                  <div className="timer-bar">
                    <div className="timer-fill" style={{ width: '100%' }} />
                  </div>
                </div>
              </div>
              <p className="muted">Начните раунд — у вас 100 секунд и 3 бесплатные замены букв.</p>
              <div className="actions-row">
                <button className="primary" onClick={startGame} disabled={loading}>
                  Старт
                </button>
                <button className="ghost" onClick={loadLeaderboard}>
                  Лидеры
                </button>
              </div>
              {banner && <div className="banner">{banner}</div>}
            </section>

            <section className="card">
              <div className="section-title">
                <div>
                  <div className="eyebrow">Таблица лидеров</div>
                  <h3>Лучшие игроки</h3>
                </div>
                <button className="ghost" onClick={loadLeaderboard}>
                  Обновить
                </button>
              </div>
              <div className="leaders">
                {leaderboard.map((leader, idx) => (
                  <div key={leader.id} className="leader-row">
                    <div className="rank">{idx + 1}</div>
                    <div className="leader-body">
                      <div className="name">{leader.username}</div>
                      <div className="muted">Лучшая попытка: {leader.best_score} | 💎 {leader.total_gems}</div>
                    </div>
                  </div>
                ))}
                {leaderboard.length === 0 && <div className="muted">Пока пусто — сыграйте первым!</div>}
              </div>
            </section>
          </>
        )}

        {status === 'playing' && (
          <>
            <section className="card gradient">
              <div className="section-title">
                <div>
                  <div className="eyebrow">Раунд</div>
                  <h2>Соберите слова из 6 букв</h2>
                </div>
                <div className="timer">
                  <span>{timer}s</span>
                  <div className="timer-bar">
                    <div className="timer-fill" style={{ width: `${timePercent}%` }} />
                  </div>
                </div>
              </div>

              <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                <div className="zones">
                  <DroppableZone id="rack" title="Буквы">
                    <SortableContext items={rackLetters.map((l) => l.id)} strategy={horizontalListSortingStrategy}>
                      <div className="letters-row">
                        {rackLetters.map((letter) => (
                          <SortableLetter key={letter.id} letter={letter} />
                        ))}
                      </div>
                    </SortableContext>
                  </DroppableZone>

                  <DroppableZone id="word" title="Перетащите сюда слово" highlight>
                    <SortableContext items={wordLetters.map((l) => l.id)} strategy={horizontalListSortingStrategy}>
                      <div className="letters-row">
                        {wordLetters.map((letter) => (
                          <SortableLetter key={letter.id} letter={letter} />
                        ))}
                      </div>
                    </SortableContext>
                  </DroppableZone>
                </div>
              </DndContext>

              <div className="builder">
                <div className="word-preview">
                  {wordLetters.map((l) => l.value).join('') || 'Тяните буквы вниз, чтобы собрать слово'}
                </div>
              <div className="builder-actions">
                  <button className="primary" onClick={saveWord} disabled={wordLetters.length < 2 || loading}>
                    Сохранить слово
                  </button>
                  <button className="ghost" onClick={resetWord}>
                    Очистить
                  </button>
                  <button className="ghost" onClick={backspace}>
                    ←
                  </button>
                </div>
              </div>

              <div className="actions-row">
                <button className="secondary" onClick={shuffleLetters} disabled={loading || status !== 'playing'}>
                  Поменять все буквы {freeSwaps > 0 ? `(осталось ${freeSwaps})` : 'за 200 💎'}
                </button>
                <button className="danger" onClick={finishGame} disabled={status !== 'playing'}>
                  Завершить раунд
                </button>
              </div>

              {banner && <div className="banner">{banner}</div>}
              {hints.length > 0 && (
                <div className="hint">
                  Подсказки (можно собрать): {hints.slice(0, 5).join(', ')}
                </div>
              )}
            </section>

            <section className="card">
              <div className="section-title">
                <div>
                  <div className="eyebrow">Ваши слова</div>
                  <h3>{words.length} / ∞</h3>
                </div>
                <button className="ghost" onClick={startGame} disabled={loading}>
                  Новые буквы
                </button>
              </div>
              <div className="words-list">
                {words.length === 0 && <p className="muted">Начните собирать слова. Минимум 2 буквы.</p>}
                {words.map((w) => (
                  <div key={w} className="word-chip">
                    {w}
                  </div>
                ))}
              </div>
            </section>

            <section className="card">
              <div className="section-title">
                <div>
                  <div className="eyebrow">Магазин</div>
                  <h3>Купите замены букв</h3>
                </div>
              </div>
              <div className="actions-row">
                <button className="ghost" disabled={shopLoading} onClick={() => buySwap(1)}>
                  1 замена — 50💎
                </button>
                <button className="ghost" disabled={shopLoading} onClick={() => buySwap(7)}>
                  7 замен — 250💎
                </button>
                <button className="ghost" disabled={shopLoading} onClick={() => buySwap(20)}>
                  20 замен — 500💎
                </button>
              </div>
              <div className="muted">Баланс: {user.gems} 💎 · Замены: {freeSwaps}</div>
            </section>
          </>
        )}
      </main>
    </div>
  );

  async function loadLeaderboard() {
    try {
      const res = await api.get<Leader[]>('/leaderboard');
      setLeaderboard(res.data);
    } catch (e) {
      //
    }
  }
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
