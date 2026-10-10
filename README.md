# Sunshine Inventory API

Laravel API for the Sunshine inventory management frontend. It provides token authentication, inventory workflows, management CRUD, alerts, and audit history.

## Requirements

- PHP 8.4+
- Composer
- A supported database (SQLite for local development, or MySQL for deployment)

## Local setup

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Configure the database settings in `.env`, then run:

```bash
php artisan migrate
php artisan serve
```

The local API is available at `http://127.0.0.1:8000/api`.

## Frontend connection

Configure the frontend's `.env` file with:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
```

For deployment, replace this with the public HTTPS URL of this API. Never commit populated `.env` files, passwords, tokens, or application secrets.

## API overview

All routes are versioned under `/api/v1`. Except for login, routes require a Laravel Sanctum bearer token.

- `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`
- `GET /inventory`
- `POST /inventory/movements`
- `POST /inventory/adjustments`
- `POST /inventory/adjustments/{adjustment}/review`
- Product, batch, and user-management CRUD under `/products`, `/batches`, and `/users`

Management routes are role protected. Warehouse Supervisors/Managers and Administration Managers manage products and batches; only Administration Managers manage users.

## Verification

```bash
php artisan test
```

## Team

- Project Manager & Scrum Master, Analysis, Compilation: Sy, John Howell J.
- Lead Developers, Database, ERD, Data Models: Suan, Christopher B.; Vasquez, Aljrome A.
- DevOps / Architecture, DFD, Business Rules: Vecina, Aryana C.; Zarate, Christopher M.
- QA, Validation, Testing Scenarios: Tinasas, Shiela Mae C.
