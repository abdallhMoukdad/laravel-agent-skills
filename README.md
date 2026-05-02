# laravel-agent-skills

A [Claude Code](https://claude.ai/code) plugin providing eight focused, proactive skills for building production-quality Laravel 12 API-only backends. Each skill activates automatically when you work in its domain, guiding you toward best practices without getting in the way.

---

## What This Plugin Does

`laravel-agent-skills` ships eight skills that cover the full surface area of a modern Laravel 12 REST/JSON API backend:

| Skill | Trigger Conditions | What It Covers |
|---|---|---|
| **laravel-eloquent** | Working with models, migrations, relationships, or query builder code | Eager loading, N+1 avoidance, scopes, casts, factories, soft deletes, mass-assignment safety, index hygiene |
| **laravel-api** | Building or modifying API routes, controllers, resources, or form requests | Resource controllers, API Resources & ResourceCollections, versioning, consistent JSON envelope, status codes, rate limiting, OpenAPI annotations |
| **laravel-architecture** | Structuring services, actions, repositories, or application layers | Single-responsibility services, action classes, repository pattern, dependency injection, feature vs. domain folders, avoiding fat controllers |
| **laravel-auth** | Implementing authentication, authorization, or token management | Sanctum API tokens, Passport, policy classes, gates, role/permission patterns, token rotation, unauthenticated vs. unauthorized responses |
| **laravel-queues** | Writing jobs, listeners, events, or background processing code | Job chunking, retries & backoff, unique jobs, batching, failed job handling, horizon configuration, queue-worker deployment |
| **laravel-testing** | Writing or running tests (feature, unit, or integration) | Pest or PHPUnit conventions, `RefreshDatabase`, HTTP test helpers, factories, mock vs. fake, asserting JSON structure, test isolation |
| **laravel-migrations** | Creating migrations, adding columns, modifying tables, or making any schema changes | Migration naming conventions, `up()`/`down()` symmetry, zero-downtime patterns (expand/contract), index hygiene, foreign key ordering, never modifying deployed migrations |
| **laravel-exceptions** | Handling exceptions, creating custom exceptions, configuring error handling, or returning consistent API error responses | `bootstrap/app.php` exception config (Laravel 12 style), domain exception hierarchy, consistent JSON error envelope, `renderable()` vs `reportable()`, `dontReport` rules |

---

## Installation

### From the Marketplace (once published)

```bash
/plugin install laravel-agent-skills@laravel
```

### Local Installation

1. Clone this repository anywhere on your machine:

   ```bash
   git clone https://github.com/abdallhMoukdad/laravel-agent-skills.git ~/plugins/laravel-agent-skills
   ```

2. Open your Claude Code settings (`~/.claude/settings.json`) and add the plugin directory:

   ```json
   {
     "plugins": [
       "~/plugins/laravel-agent-skills"
     ]
   }
   ```

3. Restart Claude Code. The eight skills will now be available automatically.

---

## Relationship to Laravel Boost

This plugin is **complementary to [Laravel Boost](https://github.com/laravel/boost)**, not a replacement.

- **Laravel Boost** provides project-level context — it reads your codebase, documents your conventions, and keeps Claude aware of your specific application's structure.
- **laravel-agent-skills** provides *proactive writing guidance* — skill prompts that fire at the right moment to steer code toward Laravel 12 best practices, regardless of the project.

Use both together for the best experience: Boost for codebase awareness, these skills for consistent quality.

---

## Requirements

- Claude Code with plugin support
- Laravel 12.x project (skills are written for Laravel 12 APIs; most patterns apply to Laravel 10/11 as well)

---

## Contributing

Contributions are welcome. To propose a change or add a new reference document:

1. Fork the repository and create a feature branch.
2. Place new reference documents under `skills/<skill-name>/references/`.
3. Keep skill prompts concise and actionable — favor bullet-point guidance over prose.
4. Open a pull request with a short description of what changed and why.

Please open an issue first for significant structural changes or new skill proposals so the direction can be discussed before implementation.

---

## License

MIT
