# Changelog

All notable changes to the **Photo Deduplicator** Nextcloud app are documented here.

## [1.3.1] — 2026-03-06

### Changed

- Aligned version declarations across `appinfo/info.xml`, `package.json`, and `package-lock.json`.
- Updated test and release documentation defaults to environment-neutral placeholders.

## [1.0.0] — 2026-03-01

### Added

- Initial release.
- SHA-256 content-based duplicate detection with streaming hash computation.
- Background cron job for periodic scanning (every 24 h).
- Stale-record cleanup job (every 7 days).
- Real-time file-event listeners (create, write, delete, rename).
- `occ photodedup:scan` command with `--all` and `--force` options.
- Vue.js frontend with:
  - Duplicate group cards with thumbnails.
  - Individual and bulk delete with safety checks (last copy is protected).
  - Scan trigger with live progress bar.
  - Statistics summary (groups, files, recoverable space).
  - "Open in Files" action to locate duplicates.
  - Empty state for first-time users.
- Pagination for large duplicate sets.
- Nextcloud App Store packaging via `make appstore`.
