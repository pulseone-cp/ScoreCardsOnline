# Scorecard Online

A lightweight, self‑hosted web app to run quick scoring/estimation rounds with colored "score cards". A moderator creates a room and controls the rounds (laps). Participants join by scanning a QR code or opening a link and tap a colored card to cast or change their vote while voting is open.

Built with plain PHP + MySQL and a simple Bootstrap/jQuery frontend. No frameworks required.


## Features
- One‑click room creation with random friendly room name and short hash
- Moderator & participant views with QR code for easy joining
- Configurable card range (0–9 or 1–9)
- Live status updates: participant count, distribution, and average
- Multiple laps (rounds) per room; next lap resets votes
- Pause/Resume voting mid‑lap (control voting_open)
- Auto‑migrating database schema (versioned SQL files) with light auto‑heal


## Architecture Overview
The app is intentionally simple and split into three parts:
- Frontend pages
  - index.php — Create a room and get links/QR
  - mod.php — Moderator dashboard (control plane)
  - join.php — Participant UI (data plane)
- Backend API
  - api.php — A single endpoint with an `action` parameter returning JSON
- Data layer
  - db.php — MySQL connection, schema migrations, and auto‑heal
  - migrations/schemaXXX.sql — Versioned SQL migrations
  - utils.php — Helpers: random IDs/names, fixed card colors, QR URL, JSON responses

External assets are served via CDN (Bootstrap 5, jQuery). QR images are provided by https://api.qrserver.com.


## Control Plane vs Data Plane
- Control plane (moderator actions)
  - Create room (index.php → api.php?action=create_room)
  - Start lap, next lap (begin a new round), pause/resume voting
  - View aggregate stats (distribution bars, average)
- Data plane (participant flow)
  - Join room (name auto‑suggested, can be customized)
  - See current lap, whether voting is open or paused
  - Tap a colored card to cast/change a vote while voting is open

The moderator receives a secret moderator token (mod hash) bound to the room. Possession of this token grants control over the session’s control plane for that room.


## How it Works (User Flow)
1. Open index.php and create a room (choose card range).
2. The app shows two links:
   - Participant link + QR code (share this)
   - Moderator link (keep this private)
3. Participants open join.php?room=<room_hash>, enter a display name (or keep the suggested one), and join.
4. The moderator opens mod.php?room=<room_hash>&mod=<moderator_token> and uses:
   - Start voting: creates the first lap
   - Next lap: closes the current lap, creates a new one
   - Stop/Resume voting: toggles whether votes can be cast during the current lap
5. Everyone sees live distribution and average; participants can change their vote until voting is closed or the next lap begins.


## Installation
### Requirements
- PHP 8.0+ (PHP 8.1+ recommended)
  - Extensions: mysqli, json, openssl (for random_bytes)
- MySQL 5.7+ or MariaDB 10.4+
- Web server (Apache, Nginx, IIS) configured to serve this directory
- Internet access for CDN (Bootstrap/jQuery) and QR images (api.qrserver.com)

### Configure database
Set the following environment variables for your web server user:
- DBHOST — MySQL host (e.g., 127.0.0.1)
- DBUSER — MySQL user
- DBPASSWORD — MySQL password
- DBNAME — Database name

Examples:
- PowerShell (current session):
  - $env:DBHOST = "127.0.0.1"; $env:DBUSER = "root"; $env:DBPASSWORD = "secret"; $env:DBNAME = "scorecard"
- PowerShell (persist for the user):
  - setx DBHOST 127.0.0.1
  - setx DBUSER root
  - setx DBPASSWORD secret
  - setx DBNAME scorecard
- Apache httpd.conf or vhost:
  - SetEnv DBHOST 127.0.0.1
  - SetEnv DBUSER root
  - SetEnv DBPASSWORD secret
  - SetEnv DBNAME scorecard
- Nginx (fastcgi_param in site config):
  - fastcgi_param DBHOST 127.0.0.1;
  - fastcgi_param DBUSER root;
  - fastcgi_param DBPASSWORD secret;
  - fastcgi_param DBNAME scorecard;

### Database migrations
- On first API call, `db.php` will automatically run all SQL migrations located in `migrations/` (schema001.sql → schemaNNN.sql) and record the current version in a `config` table.
- An additional “auto‑heal” step attempts to add/repair critical columns or indexes if migrations were skipped (e.g., add rooms.mod_hash or laps.voting_open) without failing the whole app.

### Run locally
1. Put this folder under your web server’s document root.
2. Configure DB env vars as above and ensure the database exists.
3. Open http(s)://localhost/scorecardonline/index.php in your browser.


## Data Model
Migrations define these tables (simplified):
- rooms
  - id PK, hash UNIQUE, mod_hash UNIQUE, name, card_min, card_max, current_lap_id, created_at
- laps
  - id PK, room_id FK→rooms, number (unique per room), started_at, ended_at, voting_open
- participants
  - id PK, room_id FK→rooms, name, is_moderator, joined_at, last_seen
- votes
  - id PK, lap_id FK→laps, participant_id FK→participants, value, created_at, UNIQUE (lap_id, participant_id)

See migrations/schema001.sql, schema002.sql, schema003.sql for exact DDL.


## API Reference
Base endpoint: api.php — use `action` query or form parameter. JSON responses include `{ ok: boolean, ... }`. On error, `{ ok: false, error: string }` with HTTP 4xx.

- action=create_room (POST)
  - Params: min (0 or 1), max (1..9)
  - Response: { ok, room: {hash, name, card_min, card_max}, urls: { moderator, participant } }

- action=join_room (POST)
  - Params: room (hash), name (optional; random if empty)
  - Response: { ok, participant_id, name }

- action=get_status (GET)
  - Params: room (hash), participant_id (optional)
  - Response: {
      ok,
      room: { hash, name, card_min, card_max },
      participants: number,
      lap: number,               // 0 if not started
      votes_count: number,
      distribution: { [value: string]: count },
      average: number|null,
      my_vote: number|null,      // only if participant_id provided and voted
      cards: [{ value, color }], // for UI rendering
      can_vote: boolean          // true if lap active and voting_open=1
    }

- action=start_lap (POST, moderator intent)
  - Params: room (hash)
  - Effect: If no active lap, creates lap #N+1, sets rooms.current_lap_id
  - Response: { ok, lap: number }

- action=next_lap (POST, moderator intent)
  - Params: room (hash)
  - Effect: Ends current lap (sets ended_at=NOW()), creates next lap, updates current_lap_id
  - Response: { ok, lap: number }

- action=set_voting (POST, moderator intent)
  - Params: room (hash), mod (moderator token), open (1 or 0)
  - Effect: Toggles laps.voting_open for the current lap (no‑ops if column missing)
  - Response: { ok, can_vote: boolean }

- action=vote (POST)
  - Params: room (hash), participant_id, value (within room card range)
  - Effect: Upsert vote for participant in current lap
  - Response: { ok }

- action=list_cards (GET)
  - Params: room (hash)
  - Response: { ok, cards: [{ value, color }] }

- action=ping (POST)
  - Params: participant_id
  - Effect: Updates participants.last_seen
  - Response: { ok }

HTTP codes:
- 200 on success; 400 for bad/missing params; 403 for forbidden (invalid moderator token); 404 for room not found.


## Frontend
- join.php (participant)
  - Stores participant_id and name in localStorage per room
  - Polls get_status every 2 seconds; shows new‑lap sparkle effect and optional vibration
  - Submits votes via `api.php?action=vote`
- mod.php (moderator)
  - Shows participants count, current lap, total votes, average
  - Renders distribution bars using the fixed color palette from utils.php
  - Buttons call start_lap, next_lap, set_voting
- index.php
  - Creates room via `create_room`
  - Shows QR and links for both roles


## Security Notes
- The moderator link contains a secret `mod` token in the URL; keep it private. Anyone with this token can control the room.
- There is no authentication or authorization beyond possession of the room hash and mod token.
- Use HTTPS in production. Consider placing the app behind an auth proxy if needed.
- CORS is not enabled; the intended use is same‑origin via the built‑in pages.


## Customization
- Card palette: tweak `fixed_card_colors()` in utils.php
- Default name generators: `random_room_name()` and `random_funny_name()` in utils.php
- Card range defaults: set in index.php and enforced server‑side in api.php


## Development
- Project layout
  - api.php, db.php, utils.php — backend
  - index.php, mod.php, join.php — frontend pages
  - migrations/ — SQL files named schemaNNN.sql (001, 002, 003, ...)
- Adding a migration
  1. Create `migrations/schemaXYZ.sql` with the next numeric version.
  2. Put pure SQL DDL/DML; comments are stripped during execution.
  3. The app applies pending migrations automatically on first DB access.


## Troubleshooting
- "Database environment variables are not set" — ensure DBHOST, DBUSER, DBPASSWORD, DBNAME are set for the web server process.
- "Database connection failed" — check credentials and DB reachability.
- Migrations fail — review SQL in migrations and DB permissions. The app will stop with a 500 including the failing statement.
- QR not showing — ensure the server can reach `api.qrserver.com` or replace `qr_url()` with your preferred QR generator.


## License
No license file is included. Consult the project owner before redistribution or production use.