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

- PHP 8.4 (used for development and CI)
- Composer
- MySQL
- Git

## Installation

Clone the repository:

```bash
git clone https://github.com/MahdiJaafar/tiktok-filter-generator-api.git
cd tiktok-filter-generator-api
composer install
```

For a new checkout, copy `.env.example` to `.env`, configure your local database,
and generate the application key. Then run the migrations and start the API:

```bash
php artisan key:generate
php artisan migrate
php artisan serve
```

See the [Postman guide](postman/README.md) for authentication, request examples,
and local environment setup.

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
