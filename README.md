# LeraKimGame — Word game (Laravel API + React/Vite UI)

Русская игра на составление слов за 100 секунд: регистрация по логину, сбор самоцветов, покупки замен букв, лидерборд. Бэкенд на Laravel, фронт на React/Vite, словарь русских слов включён.

## Демонстрация
- API: `/api/*`
- Демо-аккаунт: `demo / password` (сидер)

## Требования (без Docker)
- PHP 8.4 с расширениями: `pdo_mysql`, `mbstring`, `intl`, `xml`, `zip`, `openssl`, `curl`
- Composer 2.6+
- Node.js 20+, npm
- MySQL/MariaDB
- Web-server: nginx/Apache, корень `backend/public`

## Установка без Docker
```bash
# 1) Настрой .env
cp backend/.env.example backend/.env
# пропиши APP_URL, DB_*, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS

# 2) Бэкенд
cd backend
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force --seed

# 3) Фронт
cd ../frontend
npm install
npm run build
cp -r dist/* ../backend/public/
```
Настрой веб‑сервер на `backend/public` (nginx/Apache). Очистка кешей: `php artisan optimize:clear`.

## Запуск в Docker
```bash
docker compose up -d
```
API: http://localhost:8080/api  
Dev фронт: http://localhost:5173  
Для прод‑статики можно копировать `frontend/dist` в `backend/public` и отключить сервис `frontend`.

## API (основные)
- `POST /api/register` — {username,password}
- `POST /api/login`
- `POST /api/game/start`
- `POST /api/game/swap`
- `POST /api/game/check-word` — валидация слова по словарю и текущим буквам
- `POST /api/game/submit`
- `POST /api/shop/buy-swap` — {pack:1|7|20} (цены 50/250/500💎)
- `GET /api/leaderboard`

## Игровые правила
- 100 секунд, 6 букв, минимум 2 буквы в слове.
- Словарь: `backend/storage/app/dicts_ru.txt` (в репозитории).
- Бесплатные замены раз в день до 3, хранятся в БД.
- Покупки замен: 1 за 50💎, 7 за 250💎, 20 за 500💎.
- Самоцветы: 1 гем за букву валидного слова.

## Стек
- Backend: Laravel 12 (PHP 8.4), Sanctum, MySQL.
- Frontend: React, Vite, dnd-kit, zustand, axios.
- DevOps: docker-compose (опционально).

## Быстрые скрипты
- backend: `composer test`, `php artisan test`
- frontend: `npm run dev`, `npm run build`

## Лицензия
MIT
