## Word Game (Laravel API + React/Vite UI)

Русская игра на составление слов за 100 секунд: регистрация по логину, подсчёт самоцветов, покупки замен букв, лидерборд.

### Архитектура
- backend: Laravel 12 (PHP 8.4), Sanctum, MySQL; словарь слов в `storage/app/dicts_ru.txt`.
- frontend: React + Vite + dnd-kit; mobile-first UI.
- docker-compose (опционально) для dev/prod.

### Быстрый старт (без Docker)
```bash
cp backend/.env.example backend/.env
# проставьте APP_URL, DB_*, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS

cd backend
composer install --no-dev
php artisan key:generate
php artisan migrate --force --seed
php artisan storage:link   # не обязательно

cd ../frontend
npm install
npm run build
cp -r dist/* ../backend/public/
```
Настройте веб-сервер на корень `backend/public`.

### Быстрый старт (Docker)
```bash
docker compose up -d
```
API: http://localhost:8080/api  
Frontend (dev): http://localhost:5173

### API (ключевые)
- POST `/api/register` {username,password}
- POST `/api/login`
- POST `/api/game/start`
- POST `/api/game/swap`
- POST `/api/game/check-word` {session_id, word}
- POST `/api/game/submit`
- POST `/api/shop/buy-swap` {pack:1|7|20}
- GET  `/api/leaderboard`

### Игровая логика
- 100 секунд, 6 букв, минимум 2 буквы в слове.
- Словарь: `storage/app/dicts_ru.txt` (включён в репо).
- Бесплатные замены: раз в день до 3, хранится в БД.
- Стоимость замены за покупку: 1 за 50💎, 7 за 250💎, 20 за 500💎.
- Гемы: 1 гем за букву в валидном слове.

### Скрипты
- backend: `composer test`, `php artisan test`
- frontend: `npm run dev`, `npm run build`

### Деплой
- Без Docker: PHP-FPM + Nginx, корень `backend/public`.
- С Docker: использовать `docker-compose.yml` или свой Nginx как reverse-proxy к `nginx` сервиса.

### Демо-аккаунт
`demo / password` (сидером).
