# Changelog

All notable changes to the **Photo Deduplicator** Nextcloud app are documented here.

## [1.5.0] — 2026-03-06

### Added

- People insights now support a strict reference-first onboarding flow: when no person references exist, the People tab focuses on the reference candidate list for creating persons.
- Added explicit single-face-only reference candidate handling for safer person bootstrap.

### Changed

- Removed legacy signature-distance fallback clustering from People insights; clustering is now based on labeled person references only.
- Updated README machine-learning documentation with endpoint contracts, pipeline details, environment configuration, and operational validation commands.

### Fixed

- Cleaned up unused legacy people-clustering code paths to prevent accidental reactivation.
- Corrected and clarified README operations/validation formatting.

## [1.4.1] — 2026-03-06

### Fixed

- Aligned release version metadata to `1.4.1` across `appinfo/info.xml`, `package.json`, `package-lock.json`, and README.

## [1.4.0] — 2026-03-06

### Added

- **Location scan button**: GPS extraction is now triggered on demand from the Locations tab instead of running on every page load.
- **Location scan progress bar**: real-time progress indicator during scanning.
- **Database-backed location cache**: extracted GPS coordinates are stored in a new `pdd_file_locations` table and loaded instantly on page load.
- **Incremental location scanning**: only new or modified files are processed; previously scanned files (including those without GPS data) are remembered and skipped.
- New REST endpoints: `POST /api/v1/locations/scan` and `GET /api/v1/locations/scan/status`.
- New database migration, entity (`FileLocation`), and mapper (`FileLocationMapper`).
- Comprehensive unit tests for location scan service and controller endpoints.

### Changed

- Location markers are now read from the database cache instead of live EXIF extraction, eliminating the previous 20s+ latency for large libraries.
- Removed `insights_location_max_runtime_seconds` and `insights_location_exif_read_bytes` app config keys (no longer needed).

### Fixed

- Fixed `compact()` variable name mismatch (`with_location` vs `$withLocation`) in scan result reporting.
- Fixed EXIF extraction aborting entirely when a file exceeded the byte-read limit, instead of reading GPS data from the already-copied header bytes. This caused all JPEG files larger than ~2 MB to be incorrectly recorded as having no GPS coordinates.

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
