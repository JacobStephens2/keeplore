# Database Schema Documentation

**Database name:** `stewardg_artifacts`
**Engine:** MySQL / MariaDB (accessed via PHP `mysqli`)

## Overview

The `stewardg_artifacts` database supports an artifact management tool that tracks collections of items (games, books, equipment, etc.), their usage history, and the people who interact with them. Originally built around board game collection management, the system has evolved to track arbitrary artifact types. The schema supports multi-tenant usage where each authenticated user manages their own set of artifacts, players, and interaction records.

---

## Tables

### `games`

The primary artifacts table. Despite its name, it stores all artifact types (board games, books, equipment, etc.).

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `Title` | VARCHAR(255) | NO | Display name of the artifact |
| `FullTitle` | VARCHAR(255) | YES | Full / extended title |
| `type` | VARCHAR(100) | YES | Legacy text-based type (e.g. `'board-game'`). Redundant with `type_id` |
| `type_id` | INT | YES | FK to `types.id` -- the normalized type reference |
| `user_id` | INT | NO | FK to `users.id` -- the owning user |
| `Acq` | DATE | YES | Acquisition / tracking-start date |
| `KeptCol` | TINYINT(1) | YES | Legacy kept flag, dual-written with `is_kept` during the overlap release; removed by Contract (#17) |
| `is_kept` | TINYINT(1) | YES | Whether the artifact is kept in the primary collection (1 = yes, 0 = no). Kept means kept only |
| `InSecondaryCollection` | VARCHAR(10) | YES | Legacy secondary-membership flag (`'yes'` / otherwise), dual-written with `is_in_secondary_collection`; removed by Contract (#17) |
| `is_in_secondary_collection` | TINYINT(1) | NO | Whether it is in a secondary collection (1 = yes, 0 = no, default 0) |
| `Candidate` | VARCHAR(255) | YES | Candidate status or label |
| `CandidateGroupDate` | DATE | YES | Date associated with candidate grouping |
| `SS` | VARCHAR(255) | YES | Sweet spot value(s) -- comma-separated player counts |
| `MnP` | INT | YES | Minimum number of players/participants |
| `MxP` | INT | YES | Maximum number of players/participants |
| `MnT` | INT | YES | Minimum play/interaction time (minutes) |
| `MxT` | INT | YES | Maximum play/interaction time (minutes) |
| `Age` | INT | YES | Minimum recommended age |
| `age_max` | INT | YES | Maximum recommended age |
| `Wt` | VARCHAR(50) | YES | Weight / complexity rating |
| `Yr` | VARCHAR(10) | YES | Year of publication or release |
| `Av` | VARCHAR(50) | YES | Availability indicator |
| `BGG_Rat` | VARCHAR(10) | YES | BoardGameGeek rating |
| `FavCt` | INT | YES | Favorite count |
| `UsedRecUserCt` | INT | YES | Recommended user/player count |
| `Access` | VARCHAR(100) | YES | Access level or platform |
| `OrigPlat` | VARCHAR(100) | YES | Original platform |
| `System` | VARCHAR(100) | YES | System or platform |
| `KeptDig` | TINYINT(1) | YES | Legacy digital flag, dual-written with `is_digital`; removed by Contract (#17) |
| `is_digital` | TINYINT(1) | YES | Pure format flag: the item has a digital form. Independent of kept |
| `KeptPhys` | TINYINT(1) | YES | Legacy physical flag, dual-written with `is_physical`; removed by Contract (#17) |
| `is_physical` | TINYINT(1) | YES | Pure format flag: the item has a physical form. Independent of kept. An item can be both physical and digital |
| `Notes` | TEXT | YES | Free-form notes |
| `interaction_frequency_days` | DECIMAL/FLOAT | YES | Per-artifact override for the interaction frequency interval (in days) |
| `to_get_rid_of` | TINYINT(1) | NO | Whether the user has marked this artifact to get rid of (1 = yes, 0 = no, default 0). Excludes from interact-by list |

**Primary key:** `id`
**Foreign keys:**
- `user_id` -> `users.id`
- `type_id` -> `types.id`

---

### `users`

Authentication and account records for application users.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `first_name` | VARCHAR(255) | NO | User's first name |
| `last_name` | VARCHAR(255) | NO | User's last name |
| `email` | VARCHAR(255) | NO | Email address |
| `username` | VARCHAR(255) | NO | Login username (unique, min 8 chars) |
| `hashed_password` | VARCHAR(255) | NO | Bcrypt-hashed password |
| `user_group` | INT | YES | Authorization group (1 = regular user, 2 = admin) |
| `default_use_interval` | DECIMAL/FLOAT | YES | Default interaction interval in days for use-by calculations |
| `default_setting` | VARCHAR(255) | YES | Default UI setting preference |
| `daily_email` | TINYINT(1) | NO | Whether user receives the daily use-by email (1 = yes, 0 = no, default 1) |
| `player_id` | INT | YES | FK to `players.id` -- links this user account to their player record |

**Primary key:** `id`
**Foreign keys:**
- `player_id` -> `players.id`

---

### `players`

People who interact with artifacts (play games, use items, etc.). Each player belongs to a user account. A player may also be linked back to a user account via `represents_user_id`.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `user_id` | INT | NO | FK to `users.id` -- the owning user account |
| `FirstName` | VARCHAR(255) | YES | Player's first name |
| `LastName` | VARCHAR(255) | YES | Player's last name |
| `FullName` | VARCHAR(255) | YES | Computed full name (`FirstName + ' ' + LastName`) |
| `G` | VARCHAR(10) | YES | Gender or group indicator |
| `birth_year` | INT | YES | Year of birth (age is calculated dynamically) |
| `Priority` | INT | YES | Ordering priority for playgroup selection |
| `MenuPriority` | INT | YES | UI menu ordering priority |
| `represents_user_id` | INT | YES | FK to `users.id` -- set when this player record represents a user account |

**Primary key:** `id`
**Foreign keys:**
- `user_id` -> `users.id`
- `represents_user_id` -> `users.id`

---

### `responses`

Legacy one-to-one interaction tracking. Each row records a single player's participation in a single artifact interaction (play session, usage event, etc.). Multiple rows are inserted per session -- one per player involved.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `Title` | INT | NO | FK to `games.id` -- the artifact used (column name is misleading) |
| `Player` | INT | NO | FK to `players.id` -- the participant |
| `PlayDate` | DATE | YES | Date of the interaction/play session |
| `AversionDate` | DATE | YES | Date of an aversion (dislike) record |
| `PassDate` | DATE | YES | Date a player passed on the artifact |
| `RequestDate` | DATE | YES | Date a player requested the artifact |
| `Note` | TEXT | YES | Free-form notes about the interaction |
| `user_id` | INT | NO | FK to `users.id` -- the recording user |

**Primary key:** `id`
**Foreign keys:**
- `Title` -> `games.id`
- `Player` -> `players.id`
- `user_id` -> `users.id`

---

### `uses`

One-to-many interaction tracking (newer system). Each row records a single use/interaction event for an artifact. Players who participated are linked via the `uses_players` junction table.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `artifact_id` | INT | NO | FK to `games.id` -- the artifact used |
| `use_date` | DATE | YES | Date of the interaction |
| `user_id` | INT | NO | FK to `users.id` -- the recording user |
| `note` | TEXT | YES | Primary notes about the interaction |
| `notesTwo` | TEXT | YES | Secondary/additional notes |

**Primary key:** `id`
**Foreign keys:**
- `artifact_id` -> `games.id`
- `user_id` -> `users.id`

---

### `uses_players`

Junction table linking `uses` to `players` (many-to-many). Records which players participated in a given use event.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key (implied) |
| `use_id` | INT | NO | FK to `uses.id` |
| `player_id` | INT | NO | FK to `players.id` |
| `user_id` | INT | NO | FK to `users.id` -- the recording user |

**Primary key:** `id`
**Foreign keys:**
- `use_id` -> `uses.id`
- `player_id` -> `players.id`
- `user_id` -> `users.id`

---

### `proposal_outcomes` and `proposal_outcome_players`

Proposal history is separate from use history and legacy aversions. Apply
[`add-proposal-outcomes.sql`](../database/migrations/add-proposal-outcomes.sql)
before deploying the proposal pages. The migration creates new tables and is safe to rerun.

`proposal_outcomes` stores one observation per item proposal:

| Column | Type | Meaning |
|---|---|---|
| `id` | INT UNSIGNED, AUTO_INCREMENT | Proposal record ID |
| `user_id` | INT | Recording account |
| `item_id` | INT | Proposed item, `games.id` |
| `proposal_date` | DATE | Date of the proposal |
| `outcome` | ENUM | `explicit_decline` or `chose_something_else` |
| `note` | TEXT | Optional reason or circumstances, empty when omitted |
| `chosen_item_id` | INT, nullable | Optional existing alternative, `games.id` |
| `chosen_item_name` | VARCHAR(255) | Alternative's name when recorded, or a freely entered name |

`proposal_outcome_players` has a composite primary key `(proposal_id, player_id)`.
Its foreign key to the proposal cascades deletions. Each participant is recorded at most
once per proposal. Counts aggregate proposal rows directly, without joining participants.

The module validates ownership of proposed items, alternatives, and participants. References
to legacy item/player tables follow the existing application-level ownership convention;
the migration does not add constraints to those tables. A stored alternative name survives
removal of the alternative from the collection. Proposal writes never update uses or responses.

---

### `types`

Lookup table for artifact categories/types.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `objectType` | VARCHAR(100) | NO | Type name (e.g. `'board-game'`, `'book'`, `'film'`, `'equipment'`) |

**Primary key:** `id`

---

### `sweetspots`

Sweet spot (ideal player count) configurations for artifacts. One artifact can have multiple sweet spot entries.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `Title` | INT | NO | FK to `games.id` -- the artifact |
| `SwS` | VARCHAR(50) | YES | Sweet spot value (player count) |

**Primary key:** `id`
**Foreign keys:**
- `Title` -> `games.id`

---

### `playgroup`

Defines which players are currently in the active play group for artifact selection.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `ID` | INT, AUTO_INCREMENT | NO | Primary key |
| `FullName` | INT | NO | FK to `players.id` -- a player in the group (column name is misleading) |
| `user_id` | INT | NO | FK to `users.id` -- the owning user |

**Primary key:** `ID`
**Foreign keys:**
- `FullName` -> `players.id`
- `user_id` -> `users.id`

---

### `admins`

Separate admin account table (legacy -- admin access is now also managed via `users.user_group`).

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `first_name` | VARCHAR(255) | NO | Admin's first name |
| `last_name` | VARCHAR(255) | NO | Admin's last name |
| `email` | VARCHAR(255) | NO | Email address |
| `username` | VARCHAR(255) | NO | Login username (unique, min 8 chars) |
| `hashed_password` | VARCHAR(255) | NO | Bcrypt-hashed password |

**Primary key:** `id`

---

### `objects` (legacy)

Legacy artifact storage table. Superseded by `games`. Uses a simpler structure without the extended metadata fields.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `ID` | INT, AUTO_INCREMENT | NO | Primary key |
| `ObjectName` | VARCHAR(255) | NO | Name of the object/artifact |
| `Acq` | DATE | YES | Acquisition date |
| `ObjectType` | INT | YES | FK to `types.id` |
| `user_id` | INT | YES | FK to `users.id` |
| `KeptCol` | TINYINT(1) | YES | Whether the object is kept (1 = yes, 0 = no) |

**Primary key:** `ID`
**Foreign keys:**
- `ObjectType` -> `types.id`
- `user_id` -> `users.id`

---

### `use_table` (legacy)

Legacy usage tracking table for `objects`. Superseded by `uses` and `responses`.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `ID` | INT, AUTO_INCREMENT | NO | Primary key |
| `ObjectName` | INT | NO | FK to `objects.ID` -- the object used |
| `UseDate` | DATE | YES | Date of usage |
| `user_id` | INT | YES | FK to `users.id` |

**Primary key:** `ID`
**Foreign keys:**
- `ObjectName` -> `objects.ID`
- `user_id` -> `users.id`

---

### `rate_limits`

Tracks API and login request attempts for rate limiting. Rows are automatically cleaned up when they expire beyond the configured time window.

| Column | Type (inferred) | Nullable | Description |
|---|---|---|---|
| `id` | INT, AUTO_INCREMENT | NO | Primary key |
| `ip_address` | VARCHAR(45) | NO | Client IP address (supports IPv6) |
| `endpoint` | VARCHAR(255) | NO | The endpoint or action being rate-limited |
| `attempted_at` | DATETIME | NO | Timestamp of the attempt (defaults to `CURRENT_TIMESTAMP`) |

**Primary key:** `id`
**Index:** `idx_ip_endpoint_time` on (`ip_address`, `endpoint`, `attempted_at`)

---

## Entity Relationship Diagram

```
┌──────────────────┐
│      admins       │
│──────────────────│
│ id (PK)          │
│ first_name       │
│ last_name        │
│ email            │
│ username         │
│ hashed_password  │
└──────────────────┘


┌──────────────────┐         ┌──────────────────┐         ┌──────────────────┐
│      users        │────┐    │      types        │         │   rate_limits     │
│──────────────────│    │    │──────────────────│         │──────────────────│
│ id (PK)          │    │    │ id (PK)          │         │ id (PK)          │
│ first_name       │    │    │ objectType       │         │ ip_address       │
│ last_name        │    │    └────────┬─────────┘         │ endpoint         │
│ email            │    │             │                    │ attempted_at     │
│ username         │    │             │ 1:N                └──────────────────┘
│ hashed_password  │    │             │
│ user_group       │    │    ┌────────┴─────────┐
│ default_use_     │    │    │      games        │
│   interval       │    ├───>│  (artifacts)      │
│ default_setting  │    │    │──────────────────│
│ player_id (FK)───│──┐ │    │ id (PK)          │
└──────┬───────────┘  │ │    │ Title            │
       │              │ │    │ type_id (FK)─────│────> types.id
       │ 1:N          │ │    │ user_id (FK)─────│────> users.id
       │              │ │    │ Acq, KeptCol     │
       │              │ │    │ SS, MnP, MxP     │
       ▼              │ │    │ MnT, MxT, Age    │
┌──────────────────┐  │ │    │ Notes, ...       │
│     players       │  │ │    │ interaction_     │
│──────────────────│  │ │    │   frequency_days │
│ id (PK)          │<─┘ │    └──┬───┬───┬───────┘
│ user_id (FK)─────│─────┘       │   │   │
│ FirstName        │             │   │   │
│ LastName         │             │   │   │
│ FullName         │             │   │   │ 1:N
│ G, birth_year    │             │   │   │
│ Priority         │             │   │   ▼
│ MenuPriority     │             │   │  ┌──────────────────┐
│ represents_      │             │   │  │   sweetspots      │
│   user_id (FK)   │             │   │  │──────────────────│
└──┬───┬───────────┘             │   │  │ id (PK)          │
   │   │                         │   │  │ Title (FK)───────│────> games.id
   │   │                         │   │  │ SwS              │
   │   │    ┌────────────────────┘   │  └──────────────────┘
   │   │    │ 1:N                    │
   │   │    │                        │ 1:N
   │   │    ▼                        ▼
   │   │  ┌──────────────────┐     ┌──────────────────┐
   │   │  │    responses      │     │      uses         │
   │   │  │──────────────────│     │──────────────────│
   │   │  │ id (PK)          │     │ id (PK)          │
   │   │  │ Title (FK)───────│──>  │ artifact_id (FK)─│────> games.id
   │   │  │ Player (FK)──────│──>  │ use_date         │
   │   │  │ PlayDate         │     │ user_id (FK)─────│────> users.id
   │   │  │ AversionDate     │     │ note             │
   │   │  │ PassDate         │     │ notesTwo          │
   │   │  │ RequestDate      │     └────────┬─────────┘
   │   │  │ Note             │              │
   │   │  │ user_id (FK)─────│──>           │ 1:N
   │   │  └──────────────────┘              │
   │   │                                    ▼
   │   │         ┌──────────────────────────────────────┐
   │   │         │          uses_players                 │
   │   │         │──────────────────────────────────────│
   │   │         │ id (PK)                              │
   │   └────────>│ player_id (FK) ──────────────────────│────> players.id
   │             │ use_id (FK) ─────────────────────────│────> uses.id
   │             │ user_id (FK) ────────────────────────│────> users.id
   │             └──────────────────────────────────────┘
   │
   │           ┌──────────────────┐
   │           │    playgroup      │
   │           │──────────────────│
   │           │ ID (PK)          │
   └──────────>│ FullName (FK)────│────> players.id
               │ user_id (FK)─────│────> users.id
               └──────────────────┘


   LEGACY TABLES
   ─────────────

┌──────────────────┐         ┌──────────────────┐
│     objects        │         │    use_table       │
│──────────────────│         │──────────────────│
│ ID (PK)          │<────────│ ObjectName (FK)  │
│ ObjectName       │         │ ID (PK)          │
│ Acq              │         │ UseDate          │
│ ObjectType (FK)──│──> types.id │ user_id (FK) │
│ user_id (FK)─────│──> users.id └──────────────┘
│ KeptCol          │
└──────────────────┘
```

---

## Key Relationships

### Active Schema

| Relationship | Type | Join Condition | Description |
|---|---|---|---|
| `users` -> `games` | One-to-Many | `games.user_id = users.id` | A user owns many artifacts |
| `types` -> `games` | One-to-Many | `games.type_id = types.id` | Each artifact has one type category |
| `games` -> `responses` | One-to-Many | `responses.Title = games.id` | An artifact has many legacy response/play records |
| `games` -> `uses` | One-to-Many | `uses.artifact_id = games.id` | An artifact has many use records |
| `games` -> `sweetspots` | One-to-Many | `sweetspots.Title = games.id` | An artifact has many sweet spot configs |
| `players` -> `responses` | One-to-Many | `responses.Player = players.id` | A player appears in many response records |
| `uses` -> `uses_players` | One-to-Many | `uses_players.use_id = uses.id` | A use event has many player associations |
| `players` -> `uses_players` | One-to-Many | `uses_players.player_id = players.id` | A player is linked to many use events |
| `users` -> `players` | One-to-Many | `players.user_id = users.id` | A user manages many player records |
| `users` <-> `players` | One-to-One (optional) | `users.player_id = players.id` / `players.represents_user_id = users.id` | A user may be linked to a specific player record representing themselves |
| `players` -> `playgroup` | One-to-Many | `playgroup.FullName = players.id` | A player can be in multiple playgroup slots |
| `users` -> `playgroup` | One-to-Many | `playgroup.user_id = users.id` | A user defines their own playgroup |
| `users` -> `responses` | One-to-Many | `responses.user_id = users.id` | A user records many response entries |
| `users` -> `uses` | One-to-Many | `uses.user_id = users.id` | A user records many use entries |

### Legacy Schema

| Relationship | Type | Join Condition | Description |
|---|---|---|---|
| `users` -> `objects` | One-to-Many | `objects.user_id = users.id` | A user owns many legacy objects |
| `types` -> `objects` | One-to-Many | `objects.ObjectType = types.id` | A legacy object has one type |
| `objects` -> `use_table` | One-to-Many | `use_table.ObjectName = objects.ID` | A legacy object has many usage records |

---

## Interaction Tracking: Two Systems

The application has two parallel interaction tracking systems:

### 1. Legacy: `responses` table (one row per player per event)
- Each interaction creates N rows (one per player involved)
- The artifact is referenced by `responses.Title` -> `games.id`
- The player is referenced by `responses.Player` -> `players.id`
- Supports play dates, aversion dates, pass dates, and request dates

### 2. Current: `uses` + `uses_players` tables (normalized)
- Each interaction creates one row in `uses`
- Players are linked via the `uses_players` junction table
- The artifact is referenced by `uses.artifact_id` -> `games.id`
- Supports `use_date`, `note`, and `notesTwo`

Both systems are queried simultaneously in use-by calculations (e.g., in `use_by()` and `find_artifacts_by_user_id()`) via `LEFT JOIN` on both `responses` and `uses`.

---

## Notes

1. **Legacy naming: `games` table stores all artifacts, not just games.** The table was originally created for board game tracking. It now stores all artifact types (books, films, equipment, food, drinks, instruments, toys, etc.) identified by the `type` / `type_id` columns.

2. **The `objects` and `use_table` tables appear to be legacy and may be deprecated.** Their query functions are in files prefixed with `legacy_`. The `objects` table has a simpler structure than `games` and uses `ObjectName` / `ObjectType` naming. The `use_table` references `objects` rather than `games`.

3. **Column naming conventions are inconsistent (camelCase, PascalCase, snake_case).** Examples:
   - PascalCase: `Title`, `KeptCol`, `FirstName`, `PlayDate`, `AversionDate`, `ObjectName`
   - snake_case: `user_id`, `use_date`, `artifact_id`, `first_name`, `hashed_password`, `interaction_frequency_days`
   - Abbreviated: `MnP`, `MxP`, `MnT`, `MxT`, `Acq`, `Av`, `Wt`, `Yr`, `SS`, `G`, `SwS`
   - Misleading names: `responses.Title` is actually an INT FK to `games.id`; `playgroup.FullName` is actually an INT FK to `players.id`

4. **The `types` table is shared** between the active `games` schema (`games.type_id -> types.id`) and the legacy `objects` schema (`objects.ObjectType -> types.id`).

5. **The `games` table has redundant type storage:** both `type` (VARCHAR, stores the type name string) and `type_id` (INT, FK to `types.id`). Both are set on insert/update. The `type_id` column is the normalized reference; the `type` column appears to be a denormalized cache.

6. **Multi-tenancy is enforced at the application layer.** Most queries filter by `user_id = $_SESSION['user_id']`. There are no database-level row-security policies.

7. **The `rate_limits` table is auto-created** via `CREATE TABLE IF NOT EXISTS` in the `RateLimiter` class constructor, making it the only table with a known exact DDL in the codebase.

8. **Use-by date calculation** uses both `responses.PlayDate` and `uses.use_date` with `MAX()` aggregations and `CASE` expressions to determine when an artifact should next be used, based on `users.default_use_interval` or the per-artifact `games.interaction_frequency_days` override.
