# Keeplore

**Know what you own. Use what you keep.**

![Keeplore — know what you own. Use what you keep.](ui/assets/keeplore.png)

Keeplore is a self-hosted possessions tracker inspired by the Minimalists'
90/90 rule. Record when you use the things you own, see what is due for
attention, and decide what still earns its place.

Live at [keeplore.app](https://keeplore.app/).

Formerly **Artifact Manager** — see [docs/keeplore-app-name.md](docs/keeplore-app-name.md) for the naming decision record.

## Tech Stack

- **Backend**: PHP / MySQL (MySQLi) / Apache
- **Frontend**: Vanilla HTML/CSS/JavaScript
- **Auth**: JWT tokens + session-based auth with bcrypt
- **Email**: PHPMailer via SendGrid
- **Testing**: PHPUnit 10
- **Dependencies**: Composer (`firebase/php-jwt`, `phpmailer/phpmailer`)

## Features

### Item Tracking

- Create and manage items across types: board games, books, films, equipment, toys, instruments, food, drinks, and more
- Track acquisition dates, player counts, complexity, ratings
- Organize into primary/secondary collections or archive
- Candidate/exploration status for items under consideration

### Interaction Logging

- Record usage events with dates, notes, and participants
- Multi-player support per interaction
- Sweet spot tracking (ideal player counts per item)
- Per-item frequency overrides

### Usage Analysis

- Calculate interact-by dates based on configurable interaction frequency
- List items ordered by next due date to prioritize what to use next
- Default and per-item interval settings

### Proposal Outcomes

- Record explicit declines or occasions when something else was chosen for any item
- Optionally include participants, a note, and the alternative selected
- Edit or delete proposal history from the item's page
- Compare separate outcome counts from Analysis, with date filters and collection scope
- Keep proposal history separate from actual uses and interact-by dates

### Player & Playgroup Management

- Player profiles with priority and menu ordering
- Playgroup selection for defining active participants
- Aversion tracking

### REST API

- Per-user agent keys (`Authorization: Bearer`) for remote agents: collection, uses, and proposal reads plus the kept toggle
- Public docs at [`/api-docs`](https://keeplore.app/api-docs) (JSON at `/api-docs?format=json`)
- JWT session cookies and a legacy shared API key for the web app
- CRUD endpoints for items, uses, types, and users
- Cursor-based and offset-based pagination
- Search and filtering
- Rate limiting (60 requests/minute per IP)
- Structured request logging

### Multi-Tenant

- Each user manages their own items, players, and data
- User roles (regular, admin)
- Registration, login, and password reset flows

## Project Structure

```
ui/                         Web-accessible frontend
├── index.php               Dashboard when signed in; marketing home when not
├── api-docs.php            Public agent API docs
├── artifacts/              Item CRUD pages
├── uses/                   Interaction recording
├── players/                Player management
├── playgroup/              Playgroup selection
├── types/                  Item type management
├── users/                  User management
├── settings/               User settings
├── explore/                Candidate exploration
├── aversions/              Aversion tracking
├── shared/                 Header/footer templates
└── style.css               Global stylesheet

api/                        REST API endpoints
├── artifact.php            Single item CRUD
├── artifacts.php           Item listing/search
├── uses.php                Interaction recording
├── types.php               Item types
├── users.php               User management
└── private/                API-specific initialization

private/                    Backend logic (not web-accessible)
├── initialize.php          App bootstrap & constants
├── database.php            Database connection
├── environment_variables.php  Config (git-ignored)
├── functions.php           CSRF & utility helpers
├── validation_functions.php   Input validation
├── auth_functions.php      Authentication logic
├── rate_limiter.php        Rate limiting
├── cache.php               File-based caching
├── app_logger.php          Structured logging
├── classes/                OOP data access layer
│   ├── DatabaseObject.class.php
│   ├── Artifact.class.php
│   └── User.class.php
├── query_functions/        Domain-specific query modules
├── crons/                  Scheduled tasks
└── oneTimeScripts/         Migration scripts

tests/                      PHPUnit test suite
docs/                       Schema docs and naming decision record
```

## Setup

### Prerequisites

- PHP 7.x+ with MySQLi extension
- MySQL / MariaDB
- Apache with mod_rewrite
- Composer

### Installation

```bash
# Install dependencies
composer install
cd private && composer install
cd ../api/private && composer install

# Configure environment
cp private/environment_variables_template.php private/environment_variables.php
# Edit with your DB credentials, SendGrid key, JWT secret, etc.

# Set up database (see docs/DATABASE_SCHEMA.md)

# Ensure logs/ and cache/ are writable
chmod 777 logs/ cache/
```

### Configuration

`private/environment_variables.php` defines:

| Constant | Purpose |
|---|---|
| `DB_SERVER`, `DB_USER`, `DB_PASS`, `DB_NAME` | Database connection |
| `SENDGRID_API_KEY` | Email delivery |
| `ARTIFACTS_API_KEY` | API authentication key |
| `JWT_SECRET` | JWT signing key |
| `ARTIFACTS_DOMAIN` | Application domain (`keeplore.app`) |
| `API_ORIGIN` | API domain for CORS |
| `APP_NAME` | Product name for emails and copy (`Keeplore`) |
| `APP_ENV` | `development` or `production` |

### Running Tests

```bash
./vendor/bin/phpunit
```

Proposal integration tests use a disposable MySQL database per test. With a local MySQL
server available, run:

```bash
KEEPLORE_TEST_DB_HOST=127.0.0.1 KEEPLORE_TEST_DB_PORT=3306 \
  KEEPLORE_TEST_DB_USER=root vendor/bin/phpunit
```

Set `KEEPLORE_TEST_DB_PASSWORD` if needed. The test account needs permission to create and
drop databases prefixed `keeplore_test_`. Without `KEEPLORE_TEST_DB_HOST`, integration tests
are skipped; CI supplies MySQL and runs them. No application database credentials are used.

Before deploying proposal outcomes, apply
[`database/migrations/add-proposal-outcomes.sql`](database/migrations/add-proposal-outcomes.sql)
to the application database. It only creates the two proposal tables and can be rerun.

## Acknowledgments

The following LinkedIn Learning courses by Kevin Skoglund were very helpful in the development of this app: _PHP Essential Training_, _PHP with MySQL Essential Training: 1 The Basics_, and _PHP with MySQL Essential Training 2: Build a CMS_.
