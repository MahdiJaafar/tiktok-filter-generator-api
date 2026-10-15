# TikTok Filter Generator API

Backend REST API for a TikTok-style filter preset generator built with Laravel.

The application allows creators to create, manage, publish, discover, like, collect, and remix reusable filter presets containing visual adjustments, overlays, stickers, and text layers.

## Technologies

- PHP 8.4
- Laravel 13
- MySQL
- Laravel Sanctum
- PHPUnit
- Git & GitHub
- Postman
- GitHub Actions

## Main Features

Implemented features:

- User registration
- Token-based authentication
- Filter preset creation and management
- Filter layers
- Asset uploads
- Categories and tags
- Public filter gallery
- Filter search and filtering
- Likes
- Collections
- Filter remixing
- Authorization and validation
- Automated PHPUnit tests
- Postman API documentation
- AI-powered filter recommendations with deterministic fallback

## Requirements

Before running the project, make sure the following are installed:

- PHP 8.4.1 or later compatible with `composer.lock` (PHP 8.4 is used in CI).
  Although `composer.json` allows PHP 8.3, the locked Symfony dependencies require
  at least PHP 8.4.1.
- Composer
- MySQL
- Git

Enable `pdo_mysql` for the application and `pdo_sqlite` / `sqlite3` for tests.
Image upload tests require GD with PNG, JPEG and WEBP support. Relevant Laravel,
Composer and PHPUnit extensions include `ctype`, `dom`, `fileinfo`, `filter`,
`hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `Phar`, `session`,
`tokenizer`, `xml` and `xmlwriter`; many ship with PHP. Enable `curl` for HTTP calls
and `zip` for convenient Composer downloads. Check the installed environment with
`composer check-platform-reqs` after installing dependencies.

## Installation

Clone the repository:

```bash
git clone https://github.com/MahdiJaafar/tiktok-filter-generator-api.git
cd tiktok-filter-generator-api
composer install
```

For a fresh checkout, copy the example environment file:

```bash
cp .env.example .env
```

In Windows PowerShell, use `Copy-Item .env.example .env` instead. Keep an existing
`.env` when updating an installation. Generate the application key:

```bash
php artisan key:generate
```

### MySQL configuration

Create the database in MySQL before running migrations, using your MySQL client
or database administration tool:

```sql
CREATE DATABASE tiktok_filter_generator_api
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Configure the connection in your local `.env`. These are illustrative values;
use your own local database credentials:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tiktok_filter_generator_api
DB_USERNAME=root
DB_PASSWORD=your_password
```

### Database setup

```bash
php artisan migrate
php artisan db:seed
```

Seeding creates starter categories (Vintage, Cinematic, Beauty, Funny, Gaming,
Retro, Artistic) and tags (summer, warm, retro, film, neon, dark, funny, cinematic,
vintage, colorful). An existing category is required to create a filter; tags
are optional. The seeders reuse existing slugs, preserve existing records, and
can be run repeatedly without creating duplicates. They do not create users;
register a user through the API.

To find the IDs for your Postman requests, run `php artisan tinker` and inspect:

```php
App\Models\Category::all(['id', 'name', 'slug']);
App\Models\Tag::all(['id', 'name', 'slug']);
```

Use the returned IDs rather than assuming a starter entry always has ID `1`.

### Run the application

```bash
php artisan serve
```

The API is available at `http://127.0.0.1:8000/api`. For uploads up to the API's
5 MiB limit, configure PHP's `upload_max_filesize` to at least `5M` and
`post_max_size` above that size to allow multipart overhead (for example `8M`).

See the [Postman guide](postman/README.md) for authentication, request examples,
and local environment setup.

## Tests and code style

```bash
php artisan test
```

`phpunit.xml` configures an in-memory SQLite database, array-backed cache/sessions,
and isolated test data. Tests use SQLite instead of your developer MySQL database.
Upload tests use fake storage, and recommendation provider calls are mocked, so
no LLM key is required. If configuration was previously cached, run
`php artisan config:clear` before testing so the test settings take effect.

Optional read-only style check:

```bash
vendor/bin/pint --test
```

In PowerShell, use `php vendor/bin/pint --test`.

Never commit `.env`, real passwords, API keys, or exported Postman tokens.

## AI Recommendations

`GET /api/recommendations` requires a Sanctum Bearer token and returns up to five
published filter recommendations for the authenticated user. The rate limit is
10 requests per minute per user. Send no request body.

Preferences come from published filters the user has liked or saved in their
collections, including those filters' categories and tags. The service considers
up to 50 history filters, ordered by publication date, and up to 50 candidates.
It prioritizes unseen filters from other creators, using previously liked/saved
or owned filters only when needed to fill the list. Drafts and filters without
`published_at` are excluded.

An external LLM can improve candidate ranking when configured. The prompt contains
category/tag names, example liked/saved filter names, and candidate metadata;
user email, password and token fields are never included. Returned IDs are checked
against the candidate pool, and publication status is checked again before returning
the response.

When the provider is unconfigured, unavailable, times out, or returns unusable
output, the API automatically uses deterministic database-based recommendations:
category matches, shared-tag count, likes count, then publication recency (with ID
as a tie-breaker). Users without published liked/saved history receive popularity
and recency recommendations without calling an LLM.

The JSON response contains `data`, an array of `FilterResource` objects, and
`meta.source`, either `ai` or `fallback`. Valid AI choices can be supplemented by
fallback choices. Each filter includes creator id/name, category, tags, layers,
safe asset metadata where available, and `likes_count`. This endpoint is not
paginated; an empty eligible catalog returns `{"data":[],"meta":{"source":"fallback"}}`.
Missing authentication returns 401; exceeding the rate limit returns 429 with a
`Retry-After` header. Full examples are saved in the Postman collection's
**AI Recommendations** folder.

### Optional provider configuration

The following server-side settings are available through `config/services.php`:

```dotenv
LLM_PROVIDER=
LLM_API_KEY=
LLM_MODEL=
LLM_BASE_URL=
```

To enable AI ranking, choose `anthropic` (Claude) or `mistral`, supply a provider
API key, and set a model available to your provider account. `LLM_BASE_URL` is
optional: leaving it blank uses that provider's official API root. A custom root
must use HTTPS and include the API version path, such as `/v1`.

**Provider configuration is optional. Fallback recommendations work without an
LLM key. Never commit a real API key.** Keep provider credentials on the server;
do not add them to Postman or exported collections/environments.
