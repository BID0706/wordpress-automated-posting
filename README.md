# ILLE Post Generator V2

A WordPress plugin that automates SEO-optimized blog post creation via an admin UI or REST endpoint, with support for supervised (draft) and unsupervised (publish) workflows.

Built for [ille.com.ng](https://ille.com.ng).

---

## Features

### Post Generation
- Generate fully structured posts: introduction, subheadings, and conclusion
- Optional blog title — AI picks one if left blank
- Optional focus keyword — AI generates one if omitted
- Featured image generation and attachment with SEO alt text
- Short excerpt used as both post excerpt and Yoast meta description
- Auto-category assignment based on existing WordPress categories
- Yoast SEO fields populated: focus keyword, meta description, SEO title

### Workflows
| Mode | Behaviour |
|------|-----------|
| **Unsupervised** | Post is generated and published immediately |
| **Supervised** | Post is generated and saved as a draft for admin review before publishing |

### Admin UI
- **Generate Post page** — form with title, keyword, image toggle, status, and schedule options
- **Settings page** — tabbed interface covering endpoint, auth, AI models, prompts, and schedules

### REST Endpoint
- Trigger post generation externally via HTTP request
- Dual authentication: WordPress session (no key needed) or `X-API-Key` header
- Optional parameters: `topic`, `focus_keyword`, `featured_image`, `publish`
- Configurable custom endpoint slug

### Scheduler
- Up to 5 independent cron-based schedules
- Per-schedule: days of week, time, topic, post status (publish/draft), label
- Powered by WP-Cron with automatic re-scheduling

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- [Yoast SEO](https://yoast.com/wordpress/plugins/seo/) plugin (free)
- A free [Google Gemini API key](https://aistudio.google.com/app/apikey) *(Phase 2)*

---

## Installation

1. Download or clone this repository
2. Upload the `ille-post-generator-v2` folder to `/wp-content/plugins/`
3. Activate the plugin in **WP Admin → Plugins**
4. Go to **Post Generator → Settings** and configure:
   - Gemini API key *(Phase 2)*
   - Endpoint secret key (auto-generated on activation)
   - Allowed roles, prompts, and schedules as needed

---

## Usage

### Admin UI
Navigate to **Post Generator** in the WordPress sidebar, fill in the form, and click **Generate Post**.

### REST API

```bash
# On-demand with a specific topic
curl -H "X-API-Key: YOUR_SECRET" \
  "https://your-site.com/wp-json/ille/v2/generate-post?topic=Your+Topic"

# Generate a draft
curl -H "X-API-Key: YOUR_SECRET" \
  "https://your-site.com/wp-json/ille/v2/generate-post?publish=false"

# Cron job — every Monday at 8 AM
0 8 * * 1 curl -s -H "X-API-Key: YOUR_SECRET" "https://your-site.com/wp-json/ille/v2/generate-post"
```

---

## Project Structure

```
ille-post-generator-v2/
├── ille-post-generator-v2.php      # Plugin bootstrap & activation hooks
├── includes/
│   ├── class-settings.php          # Option keys, getters, defaults
│   ├── class-post-creator.php      # Post creation logic (dummy → AI in Phase 2)
│   ├── class-rest-api.php          # REST endpoint & authentication
│   ├── class-scheduler.php         # WP-Cron schedule management
│   └── class-admin.php             # Admin menu, pages & AJAX handlers
└── admin/
    ├── views/
    │   ├── main-page.php           # Generate Post form
    │   └── settings-page.php       # Tabbed settings UI
    └── assets/
        ├── admin.css               # Modern minimal styles
        └── admin.js                # Form handling, tabs, AJAX
```

---

## Development Phases

| Phase | Status | Description |
|-------|--------|-------------|
| **Phase 1** | ✅ Complete | Plugin structure, admin UI, REST endpoint, scheduler — dummy content |
| **Phase 2** | 🔜 Planned | AI content generation (Gemini), AI image generation (Pollinations.ai) |

---

## Authentication

The REST endpoint supports two authentication methods:

1. **WordPress session** — any logged-in user with an allowed role (default: Administrator) can call the endpoint without an API key
2. **API key** — pass the secret as an `X-API-Key` header or `?api_key=` query parameter for external/cron callers

The API key is auto-generated on plugin activation and can be regenerated in **Settings → Auth & Roles**.

---

## License

GPL-2.0+
