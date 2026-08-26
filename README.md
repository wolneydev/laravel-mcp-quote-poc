<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## This project

This application is a quote sales domain with a REST API and an MCP server that share one quote engine.

Read the [solution overview](.specs/SOLUTION-OVERVIEW.md) for domain maps, quote lifecycle, MCP tools, and bearer-token authentication. Setup and task specs live under [`.specs/`](.specs/).

## Installation

The stack runs in Docker Compose: PHP 8.3-FPM, Nginx on host port **8890**, and PostgreSQL 16 on host port **5439**. You need Docker with Compose and those two ports free.

```bash
git clone <repository-url>
cd markdown-processing-mcp

cp .env.example .env

docker compose up -d --build
```

Wait until Postgres is healthy, then install PHP dependencies and finish Laravel bootstrap **inside the app container**:

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate --no-interaction
docker compose exec app php artisan migrate --seed --no-interaction
```

The app is then at [http://localhost:8890](http://localhost:8890). From the host, Postgres is `localhost:5439` with the credentials in `.env` (`laravel` / `secret` by default). Inside Compose, the app uses `DB_HOST=postgres` and port `5432`.

### Quote MCP token

Authenticated quote tools talk to `http://localhost:8890/mcp/quotes`. Laravel stores only a SHA-256 hash. Generate a raw token, hash it, then keep the raw value in the MCP client environment only:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
# export MCP_QUOTE_TOKEN=<that-value>

php -r "echo hash('sha256', getenv('MCP_QUOTE_TOKEN')), PHP_EOL;"
```

Put the hash in `.env` as `MCP_QUOTE_TOKEN_HASH`. Set `MCP_QUOTE_SELLER_ACCOUNT_CODE` to an active seller from the seeder (default `VEN-000001`). Put the **raw** token in the MCP client's `MCP_QUOTE_TOKEN`. Do not commit either secret.

`.mcp.json` already points the quote server at the HTTP endpoint and sends `Authorization: Bearer ${env:MCP_QUOTE_TOKEN}`. Restart the MCP client after setting the env var.

The general application MCP (health check) still uses stdio:

```bash
docker compose exec -T app php artisan mcp:start application
```

### Tests

```bash
docker compose exec app php artisan test --compact
```

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
