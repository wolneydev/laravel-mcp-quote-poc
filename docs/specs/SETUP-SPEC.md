# Project Bootstrap Specification: Laravel 13 + PostgreSQL Docker Environment

## 1. Overview and Goal

This specification defines the requirements and implementation steps for creating a brand-new **Laravel 13** project running in a fully containerized **Docker Compose** environment with **PostgreSQL** as the primary relational database.

The project does not exist yet. The implementation must create the complete project structure from scratch, including:

- Laravel 13 application files
- Docker configuration
- PHP-FPM container
- Nginx web server
- PostgreSQL database
- PHP configuration
- Laravel environment configuration
- Persistent PostgreSQL storage

To avoid conflicts with other Docker containers or services already running on the host machine, all externally exposed ports must use non-standard ports.

---

## 2. Environment Requirements

| Service | Image / Version | Host : Container Port | Purpose |
| :--- | :--- | :--- | :--- |
| PHP / Application | PHP 8.3-FPM | Internal only | Laravel runtime |
| Nginx | `nginx:alpine` | `8890:80` | HTTP web server |
| PostgreSQL | `postgres:16-alpine` | `5439:5432` | Application database |

### Required Host Ports

- Laravel / Nginx: `8890`
- PostgreSQL: `5439`

The Laravel application must therefore be available at:

```text
http://localhost:8890