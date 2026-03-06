# Simple Multi Chat Serve (Laravel 12 + Passport OAuth2)

Backend for Nuxt frontend auth flow.

## Stack

- Laravel 12
- Laravel Passport (OAuth2, access + refresh tokens)
- PostgreSQL
- AWS S3 (avatar uploads via signed URL)

## Auth endpoints

Base URL: `http://127.0.0.1:8000`

- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/refresh`
- `GET /api/auth/me` (Bearer token)
- `POST /api/auth/logout` (Bearer token)
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`

Profile settings (Bearer token):

- `GET /api/settings/profile`
- `PUT /api/settings/profile`
- `POST /api/settings/profile/avatar-upload-url`
- `POST /api/settings/profile/avatar-upload` (multipart fallback if direct browser->S3 upload is blocked by CORS)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan passport:install
php artisan passport:client --password --name="Nuxt Password Grant Client" --provider=users
```

Then put printed credentials into `.env`:

```env
PASSPORT_PASSWORD_CLIENT_ID=...
PASSPORT_PASSWORD_CLIENT_SECRET=...
APP_FRONTEND_URL=http://localhost:3000
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=simple-multi-chat
```

## Run

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

S3 bucket CORS example (required for browser `PUT` upload from Nuxt):

```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedOrigins": ["http://localhost:3000"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }
]
```

## Password reset flow

`POST /api/auth/forgot-password` sends email via Laravel mailer.
Reset URL points to frontend route:

`{APP_FRONTEND_URL}/reset-password?token=...&email=...`

For local dev with `MAIL_MAILER=log`, link is written into `storage/logs/laravel.log`.
