# AI Development Tooling Specification: Laravel Boost + Laravel MCP

## 1. Overview and Goal

This specification defines the requirements for adding **Laravel Boost** and **Laravel MCP** to the existing Laravel 13 Docker development environment.

The goal is to provide two complementary AI capabilities:

1. **Laravel Boost**

   * Improve AI-assisted development with Laravel-specific context.
   * Provide Laravel-maintained AI guidelines and agent skills.
   * Give Cursor access to Laravel-specific MCP tools.
   * Allow the AI agent to inspect the application, database, logs, installed packages, and Laravel documentation.

2. **Laravel MCP**

   * Add first-class Model Context Protocol support to the Laravel application itself.
   * Allow the application to define its own MCP servers.
   * Provide the foundation for future custom MCP tools, resources, and prompts.

These two packages have different responsibilities and must not be treated as replacements for each other.

---

## 2. Existing Environment

The project is expected to already be running according to the base project specification.

Expected services:

```text
laravel13_app
laravel13_webserver
laravel13_postgres
```

Application container:

```text
app
```

All PHP, Composer, and Artisan commands defined in this specification must run inside the Docker application container.

For example:

```bash
docker compose exec app php artisan
```

and:

```bash
docker compose exec app composer
```

Do not require PHP or Composer to be installed directly on the host machine.

---

## 3. Install Laravel Boost

Install Laravel Boost as a development dependency:

```bash
docker compose exec app composer require laravel/boost --dev
```

Laravel Boost must remain a development-only dependency.

Verify that `composer.json` contains:

```json
{
    "require-dev": {
        "laravel/boost": "..."
    }
}
```

Do not manually define a version unless required to resolve a dependency conflict.

Use the latest compatible release supported by the installed Laravel 13 version.

---

## 4. Configure Laravel Boost

Run the Boost installer:

```bash
docker compose exec app php artisan boost:install
```

Configure Boost for **Cursor** when the installer asks which AI agent or IDE should be supported.

The installation must enable:

* Laravel Boost MCP integration
* Laravel AI guidelines
* Laravel agent skills where supported
* Laravel documentation search integration
* Application inspection tools

The generated configuration must be compatible with the existing Docker-based development environment.

---

## 5. Laravel Boost MCP Server

Laravel Boost provides its own MCP server through:

```bash
php artisan boost:mcp
```

Because PHP exists inside the Docker application container instead of directly on the host machine, Cursor must not invoke the MCP server using:

```text
php artisan boost:mcp
```

directly from the host.

Instead, Cursor must execute the MCP server through Docker Compose.

The effective command must be equivalent to:

```bash
docker compose exec -T app php artisan boost:mcp
```

The `-T` option is required to disable pseudo-TTY allocation because MCP communicates over standard input and standard output.

---

## 6. Cursor MCP Configuration

Configure Cursor to expose the Laravel Boost MCP server.

The project-level MCP configuration should be equivalent to:

```json
{
    "mcpServers": {
        "laravel-boost": {
            "command": "docker",
            "args": [
                "compose",
                "exec",
                "-T",
                "app",
                "php",
                "artisan",
                "boost:mcp"
            ]
        }
    }
}
```

Use the MCP configuration format expected by the currently installed Cursor version.

If `boost:install` creates an MCP configuration that assumes PHP is installed on the host:

```json
{
    "command": "php",
    "args": [
        "artisan",
        "boost:mcp"
    ]
}
```

replace or override it with the Docker-based configuration above.

Do not install PHP locally merely to support Laravel Boost.

---

## 7. Verify Laravel Boost

Verify that Boost is installed:

```bash
docker compose exec app php artisan list
```

Confirm that Boost Artisan commands are available.

The following command must execute successfully:

```bash
docker compose exec -T app php artisan boost:mcp
```

Cursor must be able to start and communicate with the `laravel-boost` MCP server.

The AI agent should have access to Boost capabilities such as:

* application information
* installed Laravel ecosystem packages
* database connections
* database schema inspection
* database queries
* application logs
* latest application errors
* Laravel documentation search
* project rules

Do not expose production credentials or production databases through the local MCP configuration.

---

## 8. Install Laravel MCP

Install the official Laravel MCP package:

```bash
docker compose exec app composer require laravel/mcp
```

Unlike Laravel Boost, Laravel MCP is application functionality and therefore must be installed as a normal application dependency rather than a development-only dependency.

Verify that `composer.json` contains:

```json
{
    "require": {
        "laravel/mcp": "..."
    }
}
```

Use the latest version compatible with Laravel 13.

---

## 9. Publish Laravel MCP Routes

Publish the Laravel MCP AI routes:

```bash
docker compose exec app php artisan vendor:publish --tag=ai-routes
```

The command must create:

```text
routes/ai.php
```

Do not manually create this file if the official Laravel publishing command can create it.

Verify that the generated route file is loaded correctly by Laravel.

---

## 10. Laravel MCP Architecture

Laravel MCP must be treated as the infrastructure for exposing application-specific functionality to MCP clients.

The architecture should support future implementation of:

```text
Laravel Application
        |
        ├── MCP Servers
        │
        ├── MCP Tools
        │
        ├── MCP Resources
        │
        └── MCP Prompts
```

At this stage, the goal is to install and initialize the MCP infrastructure.

Do not create arbitrary domain-specific MCP tools before their requirements are defined in a separate specification.

---

## 11. Create an Initial Application MCP Server

Create a minimal application MCP server to validate the Laravel MCP installation.

Use Laravel's official Artisan generator rather than manually creating the server class.

First inspect the available command:

```bash
docker compose exec app php artisan list
```

Then use the Laravel MCP server generator:

```bash
docker compose exec app php artisan make:mcp-server ApplicationServer
```

Use:

```text
ApplicationServer
```

as the initial server name unless Laravel's generated conventions require a more appropriate namespace or suffix.

The server exists only to establish the application MCP architecture.

Do not add business-specific tools, resources, or prompts yet.

---

## 12. Register the Application MCP Server

Register the generated MCP server using:

```text
routes/ai.php
```

Follow the API and registration conventions provided by the installed version of `laravel/mcp`.

Do not implement custom routing mechanisms when Laravel MCP already provides an official registration API.

The application MCP server and Laravel Boost MCP server must remain separate.

Conceptually:

```text
Laravel Boost MCP
    └── Development tooling for Cursor / AI agents

Laravel Application MCP
    └── MCP interface exposed by the application
```

Do not merge the two servers.

---

## 13. Directory Structure

After implementation, the project should contain AI-related files similar to:

```text
.
├── .ai/
│   ├── guidelines/
│   ├── rules/
│   └── skills/
│
├── .specs/
│   ├── SETUP.md
│   └── AI-TOOLING.md
│
├── app/
│   └── ...
│
├── routes/
│   ├── ai.php
│   ├── console.php
│   └── web.php
│
├── composer.json
├── composer.lock
├── boost.json
└── ...
```

Additional files may be generated by Laravel Boost depending on Cursor and the installed Boost version.

Do not delete files generated by Boost unless they are confirmed to be reproducible and intentionally excluded from version control.

---

## 14. Git Version Control Strategy

The specification files must be committed:

```text
.specs/SETUP.md
.specs/AI-TOOLING.md
```

The Laravel MCP application code must also be committed, including:

```text
routes/ai.php
```

and any MCP server, tool, resource, or prompt classes that become part of the application.

Project-specific AI rules stored under:

```text
.ai/rules/
```

should be committed because they represent durable development conventions for the project.

---

## 15. Generated Laravel Boost Files

Laravel Boost may generate files such as:

```text
.mcp.json
boost.json
AGENTS.md
CLAUDE.md
```

or agent-specific guideline/configuration files.

These files may be regenerated by:

```bash
php artisan boost:install
```

or:

```bash
php artisan boost:update
```

Therefore, generated Boost files that contain no project-specific information may be excluded from source control.

However, do not blindly add all AI-related files to `.gitignore`.

Use the following rule:

### Commit

```text
.specs/**
.ai/rules/**
routes/ai.php
application MCP server classes
application MCP tools
application MCP resources
application MCP prompts
composer.json
composer.lock
```

### May Be Ignored If Fully Generated by Boost

```text
.mcp.json
boost.json
agent-specific generated guideline files
```

Before modifying `.gitignore`, confirm whether the generated file contains project-specific rules that should be shared with other developers.

---

## 16. Security Requirements

MCP tools can provide powerful access to the Laravel application.

The implementation must follow these rules:

* Never place production credentials in MCP configuration files.
* Never commit secrets or API keys.
* Never configure Boost to connect directly to the production database.
* Use the project's local PostgreSQL Docker database for development inspection.
* Do not expose arbitrary shell execution through custom MCP tools.
* Do not implement destructive database MCP tools unless explicitly required by a future specification.
* Do not expose internal application secrets through MCP resources.
* Keep `.env` excluded from Git.

Laravel Boost must operate against the local development environment.

---

## 17. Docker Requirements

Installing Laravel Boost and Laravel MCP must not introduce new Docker services unless technically required.

The existing services should remain:

```text
app
webserver
db
```

No dedicated MCP Docker container is required.

Laravel Boost MCP must execute inside:

```text
app
```

using:

```bash
docker compose exec -T app php artisan boost:mcp
```

Laravel MCP application functionality must also execute as part of the Laravel application container.

---

## 18. Do Not Modify Existing Infrastructure Without Need

This implementation must not unnecessarily change:

* PostgreSQL configuration
* Nginx configuration
* existing Docker ports
* Docker network names
* database credentials
* Laravel application URL

The existing values must remain:

```text
Application:
http://localhost:8890

PostgreSQL external:
localhost:5439

PostgreSQL internal:
db:5432
```

Only modify the base Docker infrastructure if Laravel Boost or Laravel MCP requires a technical change.

If such a change becomes necessary, preserve backward compatibility with the existing development environment whenever possible.

---

## 19. Validation Commands

After installation, execute:

```bash
docker compose exec app composer show laravel/boost
```

It must report Laravel Boost as installed.

Execute:

```bash
docker compose exec app composer show laravel/mcp
```

It must report Laravel MCP as installed.

Verify Boost commands:

```bash
docker compose exec app php artisan list
```

Verify Laravel MCP routes and configuration:

```bash
docker compose exec app php artisan route:list
```

Run the existing test suite:

```bash
docker compose exec app php artisan test
```

All existing tests must continue passing.

---

## 20. Definition of Done

The implementation is complete only when all of the following requirements are satisfied:

* [ ] `laravel/boost` is installed as a development dependency.
* [ ] `laravel/mcp` is installed as an application dependency.
* [ ] `php artisan boost:install` has been executed successfully.
* [ ] Laravel Boost is configured for Cursor.
* [ ] Cursor can start Laravel Boost MCP through the Docker `app` container.
* [ ] Cursor does not depend on host-installed PHP to run Laravel Boost.
* [ ] Laravel Boost MCP uses `docker compose exec -T app php artisan boost:mcp`.
* [ ] Boost application inspection tools are available to Cursor.
* [ ] Boost Laravel documentation search is available.
* [ ] `routes/ai.php` has been published by Laravel MCP.
* [ ] An initial Laravel MCP server has been generated using the official Artisan command.
* [ ] The application MCP server is registered according to Laravel MCP conventions.
* [ ] Laravel Boost MCP and the application's MCP server remain conceptually and technically separate.
* [ ] Existing Docker services continue working.
* [ ] Laravel remains accessible at `http://localhost:8890`.
* [ ] PostgreSQL remains accessible internally at `db:5432`.
* [ ] PostgreSQL remains externally accessible at `localhost:5439`.
* [ ] Existing Laravel migrations continue working.
* [ ] Existing automated tests pass.
* [ ] No production credentials or secrets are introduced into MCP configuration.
* [ ] Project specifications and application MCP source code are committed to Git.

---

## 21. Expected Result

After completing this specification, the repository should support AI-assisted Laravel development through Cursor using Laravel Boost:

```text
Cursor
   |
   | MCP / stdio
   v
docker compose exec -T app
   |
   v
php artisan boost:mcp
   |
   v
Laravel 13 Application
   |
   ├── Application information
   ├── Database inspection
   ├── Logs
   ├── Errors
   ├── Laravel documentation
   └── Project rules
```

The Laravel application must independently have the official Laravel MCP infrastructure available for future application-specific MCP functionality:

```text
AI Client
    |
    v
Laravel MCP Server
    |
    ├── Tools
    ├── Resources
    └── Prompts
```

These two capabilities must coexist without coupling application-specific MCP functionality to Laravel Boost.
