# Changelog

All notable changes on the `rpi-restructured` / `rpi-restructured-2` branches
(2026-07-16), newest first.

## rpi-restructured-2

### Fixed
- **32-bit Telegram ID truncation** — IDs above 2³¹ broke login on 32-bit
  (armhf) Raspberry Pi OS builds: `json_decode` returned them as floats,
  failing validation, and `(int)` casts clamped them. initData is now decoded
  with `JSON_BIGINT_AS_STRING`, the session stores the ID as a string, and
  Telegram IDs stay strings end to end (`SECONDARY_TG_ID` included).
- **initData decoding** — `tg_parse_qs()` uses `rawurldecode`; `urldecode`
  turned a literal `+` into a space, corrupting the data-check-string and
  permanently failing login for affected users.
- **Sessions now expire** — `auth_at` was stored but never checked. Sessions
  older than `SESSION_MAX_AGE_DAYS` (default 7) are dropped; re-auth is
  invisible because the greeting bootstrap re-validates Telegram initData.
- **Escaping** — `view_attendance.php` back-URL is HTML-escaped in its
  `onclick` (inputs were already digit-filtered; defense in depth).
- `view_group_attendance.php` markup: proper `<!DOCTYPE html>`, attribute
  spacing.

### Changed
- Deduplicated: greeting's `initials()` merged into `subject_abbr()`
  (helpers.php); single `nav()` in script.js (inline copy in index.php
  removed); the existing-attendance + marker-names block shared as
  `getAttendanceWithNames()` in db.php.
- Vestigial `tg_id` / `actor_tg` URL parameters removed everywhere the server
  ignores them (identity comes from the session). They remain only where they
  mean something: `greeting.php?tg_id=` (admin view-as) and
  `view_attendance.php?tg_id=` (which student to show).
- Dead code removed: unused `period-slider` block in script.js, unused
  `data-tg-id` body attributes.
- The pre-auth bootstrap page now cache-busts script.js too — the one place a
  stale cached file could break login.

### Added
- CI (`.github/workflows/lint.yml`): `php -l` on every PHP file plus
  `node --check script.js` on each push and pull request.

## rpi-restructured

### Security
- **Server-side authorization of attendance writes** — `index.php` and
  `edit_attendance.php` now validate every submitted `user_id`/`schedule_id`
  against the resolved group's student list and that day's schedule
  (subgroup match included). Previously the IDs were written as sent, so any
  monitor/moderator could write attendance into any group.
- nginx sample config denies `.sql`/`.md`/`.sh`/`.git`/`.env` in the webroot.

### Fixed
- **Timezone** — `APP_TZ` (default `Europe/Chisinau`) is set once via
  `date_default_timezone_set()`; previously `date()` used the server zone
  while lock logic used Chișinău explicitly, putting attendance on the wrong
  day near midnight.
- **Duplicate submissions** — resubmitting a day returns 409 with a clear
  message instead of an opaque 500 off the unique key; the client now shows
  the server's error text.
- **Subgroup lessons were invisible on logging pages** — schedule is fetched
  by `group_id` (`getGroupScheduleForDate()`), subgroup lessons included;
  headers are tagged `(sg N)` and non-applicable students get a dash cell.
- README: database name consistent with `init_db.sql` everywhere
  (`attendence_utm`), install step references `init_db.sql`, environment
  variables documented.

### Changed / removed
- `helpers.php` introduced: `proxyTgIdForGroup()` (was defined 3× with
  diverging signatures), `subject_abbr()`, subgroup rules, `asset()`.
- Dead code removed: `SECRET_TOKEN`, `API_URL`, `markAttendance()`,
  `getTodaySchedule()`, `getWeekSchedule()`, `getUserStats()`.
- Hardcoded values moved to env-backed constants: `PRIMARY_GROUP_NAME`,
  `LAB_FEE_LEI`, `MODERATOR_GRACE_MIN`, `MODERATOR_FALLBACK_CUTOFF`.
- `tableb.css`/`tablec.css` merged into `style.css`, scoped under
  `.page-greeting` / `.layout-big` so nothing leaks into other pages; the
  Big/Compact slider toggles a body class instead of a stylesheet `media`
  attribute. Zero intended visual change.

### Performance
- `view_attendance.php`: ~24 SQL round trips → ~5 (one conditional
  aggregation for all stat cards + term, one absence-row query split per
  period in PHP, one `GROUP BY` month for the streak, lab misses folded into
  the per-subject query). Window logic property-tested against the old
  behavior over 2000 randomized datasets.
- Composite `idx_sched_date (schedule_id, date)` index — covering for the
  query every logging-page load runs. Existing installs: see the `ALTER`
  in `init_db.sql`'s comments.
- Cache-busting via `asset()` (`?v=<filemtime>`) on all pages plus 30-day
  cache headers in the nginx sample — repeat visits serve css/js from the
  browser or Cloudflare edge instead of round-tripping to the Pi.
