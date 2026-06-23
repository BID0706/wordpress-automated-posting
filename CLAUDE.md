# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Git Workflow

**Never commit directly to `main`.** All changes go to `stage` first, then a PR is raised to `main`.

```bash
git checkout stage
# make changes, commit
GIT_SSH_COMMAND="ssh -i ~/.ssh/id_ed25519_github" git push origin stage
# then open PR at https://github.com/BID0706/wordpress-automated-posting/compare/main...stage
```

The SSH config is not system-trusted on this machine, so `GIT_SSH_COMMAND` must be prepended to every `git push`, `git fetch`, and `git pull` that hits the remote.

## Plugin Architecture

This is a WordPress plugin. There is no build step — all PHP/CSS/JS is served directly. To test changes, the plugin folder must be present in `wp-content/plugins/` on a running WordPress installation (ille.com.ng).

**Bootstrap:** `ille-post-generator-v2.php` defines constants (`ILLE_PG_VERSION`, `ILLE_PG_DIR`, `ILLE_PG_URL`, `ILLE_PG_BASENAME`) and requires all class files. Class instantiation happens inside `ille_pg_init()` which fires on `plugins_loaded`.

**Class responsibilities:**

| File | Role |
|---|---|
| `includes/class-settings.php` | All option key constants, per-user API key helpers, model registry, default prompts |
| `includes/class-rest-api.php` | Registers `ille/v2/<slug>` REST route; handles API key auth and logs auth failures |
| `includes/class-post-creator.php` | Single `create(array $args)` method; currently Phase 1 (dummy content); Phase 2 will call an AI generator |
| `includes/class-scheduler.php` | WP-Cron wrapper for up to 5 named schedules; hook prefix `ille_pg_schedule_` |
| `includes/class-admin.php` | Admin menus, asset enqueuing, all `wp_ajax_ille_pg_*` handlers |
| `includes/class-logger.php` | JSONL audit log at `wp-uploads/ille-pg-logs/ille-pg-audit.log` |
| `admin/views/main-page.php` | Generate Post form UI |
| `admin/views/settings-page.php` | Tabbed settings UI (Endpoint / Auth & Roles / AI Models / Prompts / Schedules / Activity Log) |
| `admin/assets/admin.css` | Self-contained design system using CSS custom properties (`--ille-accent`, `--ille-success`, etc.) |
| `admin/assets/admin.js` | jQuery: tab switching, AJAX form handling, `collectSettings()` serialiser, log UI |

## Key Patterns

**Settings serialisation:** `collectSettings()` in `admin.js` builds a nested object from `settings[key]`-named form fields and returns `data.settings` (not `data`) to avoid double-nesting when passed as `{ settings: formData }` in the AJAX call.

**Custom endpoint slug + rewrite flush:** Changing the slug in settings sets an `ille_pg_needs_flush` option flag. On the next page load, `admin_init` checks the flag and calls `flush_rewrite_rules()` — after `rest_api_init` has already re-registered the new route. Never call `flush_rewrite_rules()` inside an AJAX handler.

**Per-user API keys:** Stored in user meta (`ille_pg_api_key`). `ILLE_PG_Settings::get_user_by_api_key()` does a `get_users()` meta query to resolve a key to a user. The resolved user becomes the post author. Admins see all keys; non-admins see only their own.

**Prompt sanitisation:** Post and image prompts must use `wp_kses_post()`, not `sanitize_textarea_field()` — the latter strips HTML tags that the prompts may contain.

**Phase 1 vs Phase 2:** `ILLE_PG_Post_Creator::create()` currently calls `generate_dummy_content()`. Phase 2 will replace this with a real AI generator (Gemini 2.0 Flash via free tier). The `$args` array accepted by `create()` is the stable interface between the scheduler/REST/admin and the content layer.

**Audit log format:** JSONL — one JSON object per line. Fields: `ts`, `event`, `trigger`, `uid`, `uname`, `data`. The log directory is protected by `.htaccess` (Deny from all). Sensitive values (API keys) are masked as `••••••••` in log entries.

## REST Endpoint

Default URL: `https://ille.com.ng/wp-json/ille/v2/generate-post`  
Namespace: `ille/v2` · Route: configurable via Settings → Endpoint tab  
Auth: `X-API-Key` header or `api_key` query param (per-user key), or a logged-in session with an allowed role.  
Allowed params (configurable): `topic`, `focus_keyword`, `featured_image`, `publish`

## WordPress Dependencies

- **Yoast SEO** — post meta fields `_yoast_wpseo_focuskw`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_title` are written on every post creation.
- **WP-Cron** — schedules fire via `wp_schedule_single_event`; they are single-event and rescheduled after each run. `sync_schedules()` clears and rebuilds all cron events whenever schedule settings are saved.
- **WordPress REST API** — routes registered on `rest_api_init`.
