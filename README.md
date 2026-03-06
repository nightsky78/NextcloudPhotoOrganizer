# Photo Organizer for Nextcloud

> Version **1.5.0**  
> Duplicate detection + local AI-powered image classification + location insights

Photo Organizer helps you clean up large photo libraries in Nextcloud by combining two workflows:

- **Deduplicator**: finds exact duplicate files by SHA-256 content hash.
- **Classifier**: groups images into practical categories using a local ML worker (with safe heuristic fallback).
- **People**: 
- **Location**:

It is designed to be safe, auditable, and practical for self-hosted environments.

---

## Why this app

- Reduce storage waste from exact duplicate files.
- Sort large media libraries quickly (documents, memes, nature, family, object).
- Keep everything local when ML mode is enabled (no cloud inference required).
- Preserve safety defaults (no silent destructive operations).

---

## Feature overview

### Deduplicator function

- Content-based duplicate detection (SHA-256).
- Streaming hash computation (8 MiB chunks) for stable memory usage.
- Keep-one and bulk delete workflows.
- Last-copy protection.
- Real-time index maintenance via file event listeners.
- Scheduled background scan and stale index cleanup.

### Classifier function

- Local ML-first classification via HTTP worker.
- Automatic fallback to heuristics if ML is unavailable or unsuitable.
- Confidence + indicator metadata per file.
- Move and delete actions directly from category views.
- Scope toggle in UI:
  - **Whole drive**
  - **Photos folder only** (`Photos/` and `photos/`)

### People function

- Face detection and signature extraction via local ML worker (`/face-signature`).
- Reference-first person workflow: create person references first, then load matched photos.
- Single-face reference safety: only photos with exactly one detected face can be used as references.
- Person labeling and renaming from the People tab.
- Incremental loading of photos per person cluster.
- Scope toggle support:
  - **Whole drive**
  - **Photos folder only** (`Photos/` and `photos/`)

### Location function

- On-demand EXIF GPS scanning from the Locations tab.
- Incremental scan behavior (new/changed files only).
- Database-backed location cache for fast reloads.
- Progress tracking during location scan.
- Map markers grouped by proximity.
- Scope toggle support:
  - **Whole drive**
  - **Photos folder only** (`Photos/` and `photos/`)

### Shared UX

- Scope toggle is available in both tabs.
- Pagination for large result sets.
- Progress state for scan/classification jobs.

---

## Compatibility

| Component | Version |
|---|---|
| Nextcloud | 28 – 32 |
| PHP | >= 8.1 |
| Node.js (build only) | >= 20 |
| Python (optional ML worker) | >= 3.10 |

---

## Installation

### Option A: App Store (recommended)

1. Open Nextcloud admin settings.
2. Go to **Apps**.
3. Search for **Photo Organizer**.
4. Install and enable.

### Option B: Manual install

1. Clone into your Nextcloud `custom_apps` (or `apps`) directory:

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/nightsky78/NextcloudPhotoOrganizer.git photodedup
cd photodedup
```

2. Build frontend assets and install PHP dependencies:

```bash
make
composer install --no-dev --optimize-autoloader
```

3. From your Nextcloud root, enable the app via OCC:

```bash
cd /path/to/nextcloud
sudo -u www-data php occ app:enable photodedup
```

4. Verify it is registered and enabled:

```bash
sudo -u www-data php occ app:list | grep photodedup
```

5. Open Nextcloud UI → **Apps** and search for **Photo Organizer** (id: `photodedup`).

If it does not appear immediately, refresh app metadata and retry:

```bash
sudo -u www-data php occ maintenance:repair
sudo -u www-data php occ app:disable photodedup
sudo -u www-data php occ app:enable photodedup
```

### Upgrade

Replace app files, then run:

```bash
php occ upgrade
```

---

## Quick start

1. Open **Photo Organizer** from the Nextcloud navigation.
2. In **Deduplicator**:
   - Click **Scan for duplicates**.
   - Review groups and delete unwanted copies.
3. In **Classifier**:
   - Click **Classify images**.
   - Review grouped categories.
   - Move or delete selected files.
4. In **People**:
  - Create person references from clear single-face photos.
  - Save person labels.
  - Review grouped photos per person.
5. In **Locations**:
   - Click **Scan locations** to extract GPS data from EXIF metadata.
   - A progress bar shows scan status.
   - Markers appear on the map, grouped by proximity.
   - Subsequent scans are incremental (only new/changed files are processed).
6. Use the **scope toggle** in all tabs:
   - **Whole drive** for complete view.
   - **Photos folder** for `Photos/`-only workflows.

---

## Safety model

- No automatic destructive cleanup.
- Last remaining duplicate copy is protected.
- Deletes go to Nextcloud Trash (if Trashbin is enabled).
- Scanner/classifier read file content; they do not modify bytes.
- Move operations validate target paths and resolve name collisions safely.

---

## Local ML worker (recommended setup)

The app supports a local worker in `ml_worker/`.

### Machine learning functions

The ML worker provides two production endpoints and one health endpoint:

- `GET /health`
  - Liveness and model metadata.
- `POST /classify`
  - Zero-shot image classification across app categories (`document`, `meme`, `nature`, `family`, `object`).
  - Returns best category, confidence, model id, algorithm version, and top prompt-level labels.
- `POST /face-signature`
  - Face detection plus deterministic face signatures used by People insights.
  - Returns `has_face`, `face_count`, primary signature/confidence, and per-face signatures.

Classifier pipeline (`/classify`):

1. Request auth check (`Bearer` token optional, required when configured).
2. Image safety checks (non-empty, max bytes).
3. EXIF-aware normalization (`ImageOps.exif_transpose`) and RGB conversion.
4. Optional resize to max dimension.
5. Prompt expansion from category prompt map.
6. Zero-shot inference (Transformers pipeline).
7. Per-category confidence aggregation and top-category selection.

Face-signature pipeline (`/face-signature`):

1. Same auth + input safety preprocessing.
2. Face detection (YuNet preferred, Haar cascade fallback).
3. Per-face embedding/signature generation:
   - SFace embedding if available.
   - CLIP image embedding fallback.
   - Perceptual hash fallback for degraded cases.
4. Signature normalization/quantization to `emb:v1:` compact representation.
5. Confidence computation using face area + detector score.

### Worker API contract

- `GET /health`
- `POST /classify` (`multipart/form-data`)
  - `file`: image bytes
  - `candidate_labels`: comma-separated categories

Expected response:

```json
{
  "category": "nature",
  "confidence": 0.91,
  "model": "openai/clip-vit-large-patch14",
  "algorithm_version": "2026-03-02-local-ai-v1",
  "labels": [
    {"name":"a_nature_landscape","score":0.91,"category":"nature"}
  ]
}
```

- `POST /face-signature` (`multipart/form-data`)
  - `file`: image bytes

Expected response (face found):

```json
{
  "has_face": true,
  "face_count": 2,
  "signature": "emb:v1:...",
  "confidence": 0.94,
  "faces": [
    {"face_index":1,"signature":"emb:v1:...","confidence":0.94},
    {"face_index":2,"signature":"emb:v1:...","confidence":0.78}
  ],
  "algorithm_version": "2026-03-02-local-ai-v1"
}
```

Expected response (no face found):

```json
{
  "has_face": false,
  "face_count": 0,
  "algorithm_version": "2026-03-02-local-ai-v1"
}
```

### Docker deployment

```bash
cd ml_worker
docker build -t photodedup-ml-worker:latest .
docker run -d \
  --name photodedup-ml-worker \
  --restart unless-stopped \
  -p 127.0.0.1:8008:8008 \
  -v /srv/nextcloud/data/ml-worker-cache:/root/.cache/huggingface \
  photodedup-ml-worker:latest
```

Recommended environment variables:

- `ML_WORKER_MODEL` (default: `openai/clip-vit-large-patch14`)
- `ML_WORKER_MAX_IMAGE_BYTES` (default: `12582912`)
- `ML_WORKER_MAX_DIMENSION` (default: `2048`)
- `ML_WORKER_TOP_PROMPTS_PER_CATEGORY` (default: `2`)
- `ML_WORKER_CONFIDENCE_BIAS` (default: `1.0`)
- `ML_WORKER_ALGORITHM_VERSION` (default: `2026-03-02-local-ai-v1`)
- `ML_WORKER_TOKEN` (optional bearer token)
- `ML_WORKER_EMBEDDING_DIMS` (default: `128`, min `32`, max `128`)
- `ML_WORKER_MODEL_CACHE_DIR` (default: `/tmp/ml_worker_models`)
- `ML_WORKER_YUNET_MODEL_URL` (YuNet detector model URL)
- `ML_WORKER_SFACE_MODEL_URL` (SFace recognizer model URL)
- `ML_WORKER_YUNET_SCORE_THRESHOLD` (default: `0.90`)
- `ML_WORKER_YUNET_NMS_THRESHOLD` (default: `0.30`)
- `ML_WORKER_YUNET_TOP_K` (default: `5000`)
- `ML_WORKER_MIN_FACE_SIZE` (default: `48`, minimum `24`)

### Python venv deployment

```bash
cd ml_worker
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
uvicorn app:app --host 127.0.0.1 --port 8008
```

### Nextcloud app ML configuration

```bash
php occ config:app:set photodedup ml_classifier_enabled --value=true
php occ config:app:set photodedup ml_classifier_endpoint --value=http://photodedup-ml-worker:8008/classify
php occ config:app:set photodedup ml_classifier_timeout_seconds --value=20
php occ config:app:set photodedup ml_classifier_max_file_bytes --value=12582912
php occ config:app:set photodedup ml_classifier_min_confidence --value=0.15
php occ config:app:set photodedup ml_classifier_retries --value=1

# People insights tuning
php occ config:app:set photodedup insights_people_max_file_bytes --value=52428800
php occ config:app:set photodedup insights_people_min_face_confidence --value=0.35
php occ config:app:set photodedup insights_people_require_family_category --value=true
php occ config:app:set photodedup insights_people_min_family_confidence --value=0.20

# Location insights
# Location data is now cached in the database and scanned on demand.
# No runtime tuning needed — scan progress is shown in the UI.
```

Optional auth token:

```bash
php occ config:app:set photodedup ml_classifier_token --value=YOUR_TOKEN
```

Important for containerized Nextcloud: do **not** use `127.0.0.1` unless the worker runs in the same container. Use a reachable hostname on the same Docker network (for example `photodedup-ml-worker`).

---

## Operations

### CLI commands

```bash
# Scan one user
php occ photodedup:scan johannes

# Scan all users
php occ photodedup:scan --all

# Force rehash
php occ photodedup:scan --force johannes
```

### Validation checks

```bash
# App is enabled
php occ app:list | grep photodedup

# ML endpoint reachable from Nextcloud runtime
docker exec nextcloud-app php -r 'echo file_get_contents("http://photodedup-ml-worker:8008/health");'

# Recent worker activity
docker logs --tail 50 photodedup-ml-worker
```

### Playwright E2E tests

This repository includes browser E2E tests for the Nextcloud UI app flow in `tests/E2E/photodedup-ui.spec.ts`.

1. Install dependencies:

```bash
npm ci
npx playwright install
```

2. Set environment variables:

```bash
export NC_E2E_BASE_URL="https://nextcloud.example.com"
export NC_E2E_USER="test"
export NC_E2E_PASSWORD="<password>"
```

3. Run tests:

```bash
npm run e2e:list
npm run e2e
```

The tests are non-destructive by default and validate login plus Duplicates/Classifier/People/Locations tab behavior.

---

## Troubleshooting

### Scope toggle not visible after upgrade

- Ensure app version updated in Nextcloud.
- Hard refresh browser (`Ctrl+Shift+R`).
- Re-enable app if needed:

```bash
php occ app:disable photodedup
php occ app:enable photodedup
```

### Classifier mostly falls back to heuristics

- Confirm ML is enabled and endpoint is configured.
- Check worker logs for `POST /classify` responses.
- Ensure model cache has enough disk space.
- Note: unsupported/invalid images can legitimately return fallback.

### Worker returns many `422` responses

- Usually indicates unsupported/corrupt image payloads.
- Expected behavior: app falls back per-file and continues run.

---

## Development

```bash
# Install deps
make dev-setup

# Build frontend
make build

# Watch frontend
make watch

# Tests
make test

# Lint
make lint
```

---

## Architecture summary

Key layers:

- `Controller/`: REST endpoints for deduplicator and classifier.
- `Service/`: scan, dedup, classify business logic.
- `Db/`: entity mappers and scoped query logic.
- `Listener/`: real-time index updates on file events.
- `src/`: Vue frontend.
- `ml_worker/`: optional local AI inference service.

---

## Publish checklist

Before publishing/releasing:

1. Bump versions consistently in `appinfo/info.xml`, `package.json`, `package-lock.json`, `CHANGELOG.md`, and README.
2. Build frontend (`make build`) and verify assets updated.
3. Run PHP syntax checks and lint/tests.
4. Validate app install/upgrade on a clean Nextcloud instance.
5. Verify both scopes (`all`, `photos`) in Deduplicator + Classifier.
6. Validate ML mode and fallback mode.
7. Confirm README version/features match shipped behavior.

---

## License

AGPL-3.0-or-later. See `LICENSES/AGPL-3.0-or-later.txt`.
