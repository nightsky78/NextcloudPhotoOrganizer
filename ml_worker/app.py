from __future__ import annotations

import os
from functools import lru_cache
from io import BytesIO
from typing import Any

import cv2
import imagehash
import numpy as np
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi import Header
from PIL import Image, ImageOps
from transformers import pipeline

APP_TITLE = "PhotoDedup ML Classifier"
DEFAULT_MODEL = os.getenv("ML_WORKER_MODEL", "openai/clip-vit-large-patch14")
MAX_IMAGE_BYTES = int(os.getenv("ML_WORKER_MAX_IMAGE_BYTES", str(12 * 1024 * 1024)))
MAX_IMAGE_DIMENSION = int(os.getenv("ML_WORKER_MAX_DIMENSION", "2048"))
TOP_PROMPTS_PER_CATEGORY = max(1, int(os.getenv("ML_WORKER_TOP_PROMPTS_PER_CATEGORY", "2")))
MODEL_CONFIDENCE_BIAS = float(os.getenv("ML_WORKER_CONFIDENCE_BIAS", "1.0"))
AUTH_TOKEN = os.getenv("ML_WORKER_TOKEN", "").strip()
ALGORITHM_VERSION = os.getenv("ML_WORKER_ALGORITHM_VERSION", "2026-03-02-local-ai-v1")

CATEGORY_PROMPTS = {
    "document": [
        "a document scan with text",
        "a screenshot of an app or website",
        "an invoice or receipt",
        "printed page with mostly text",
    ],
    "meme": [
        "an internet meme",
        "a social media post image",
        "a funny image with caption text",
        "chat app forwarded image",
    ],
    "nature": [
        "a nature landscape",
        "mountains, forest, sea, or outdoor scenery with no close people",
        "a travel landscape photo",
        "outdoor scenic view",
    ],
    "family": [
        "a family photo with people",
        "people portrait",
        "group of people indoors or outdoors",
        "a child or kids in a photo",
        "a person taking a selfie",
    ],
    "object": [
        "a photo of an object",
        "product shot",
        "close-up of an item",
        "indoor clutter or household items",
        "car interior or dashboard",
    ],
}

app = FastAPI(title=APP_TITLE)
_classifier = None


def get_classifier():
    global _classifier
    if _classifier is None:
        _classifier = pipeline(
            "zero-shot-image-classification",
            model=DEFAULT_MODEL,
        )
    return _classifier


@lru_cache(maxsize=1)
def category_prompt_map() -> dict[str, list[str]]:
    normalized: dict[str, list[str]] = {}
    for category, prompts in CATEGORY_PROMPTS.items():
        clean = []
        for prompt in prompts:
            value = prompt.strip().lower()
            if value and value not in clean:
                clean.append(value)
        normalized[category] = clean
    return normalized


def require_authorization(authorization: str | None) -> None:
    if AUTH_TOKEN == "":
        return
    if authorization is None or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Missing bearer token")
    token = authorization.removeprefix("Bearer ").strip()
    if token != AUTH_TOKEN:
        raise HTTPException(status_code=403, detail="Invalid bearer token")


def preprocess_image(raw: bytes) -> Image.Image:
    image = Image.open(BytesIO(raw))
    image = ImageOps.exif_transpose(image).convert("RGB")
    if max(image.width, image.height) > MAX_IMAGE_DIMENSION:
        image.thumbnail((MAX_IMAGE_DIMENSION, MAX_IMAGE_DIMENSION), Image.Resampling.LANCZOS)
    return image


@lru_cache(maxsize=1)
def get_face_cascade() -> cv2.CascadeClassifier:
    return cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")


def detect_faces(image: Image.Image) -> list[tuple[int, int, int, int]]:
    rgb = np.array(image)
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    cascade = get_face_cascade()
    faces = cascade.detectMultiScale(
        gray,
        scaleFactor=1.1,
        minNeighbors=5,
        minSize=(36, 36),
    )
    return [tuple(map(int, face)) for face in faces]


def build_face_signature(image: Image.Image, faces: list[tuple[int, int, int, int]]) -> tuple[str, float]:
    best = max(faces, key=lambda box: box[2] * box[3])
    x, y, w, h = best
    crop = image.crop((x, y, x + w, y + h)).convert("L")
    signature = str(imagehash.phash(crop, hash_size=16))

    area_ratio = float((w * h) / max(1, image.width * image.height))
    confidence = min(1.0, max(0.3, 0.5 + (area_ratio * 2.0) + (len(faces) * 0.05)))
    return signature, round(confidence, 4)


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "model": DEFAULT_MODEL,
        "algorithm_version": ALGORITHM_VERSION,
    }


@app.post("/classify")
async def classify(
    file: UploadFile = File(...),
    candidate_labels: str = Form("document,meme,nature,family,object"),
    authorization: str | None = Header(default=None),
) -> dict[str, Any]:
    require_authorization(authorization)

    raw = await file.read()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty file")
    if len(raw) > MAX_IMAGE_BYTES:
        raise HTTPException(status_code=413, detail="File too large")

    labels = [label.strip() for label in candidate_labels.split(",") if label.strip()]
    if not labels:
        labels = ["document", "meme", "nature", "family", "object"]

    try:
        image = preprocess_image(raw)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"Invalid image data: {exc}") from exc

    hypothesis_labels: list[str] = []
    mapping: dict[str, str] = {}
    prompt_order: dict[str, int] = {}
    prompt_index = 0
    prompt_map = category_prompt_map()

    for category in labels:
        prompts = prompt_map.get(category, [category])
        for prompt in prompts:
            hypothesis_labels.append(prompt)
            mapping[prompt] = category
            prompt_order[prompt] = prompt_index
            prompt_index += 1

    classifier = get_classifier()
    outputs = classifier(image, candidate_labels=hypothesis_labels)

    if not outputs:
        raise HTTPException(status_code=500, detail="No model output")

    per_category_samples: dict[str, list[float]] = {label: [] for label in labels}
    ranked_labels: list[dict[str, Any]] = []

    for row in outputs:
        prompt = str(row.get("label", "")).strip()
        score = float(row.get("score", 0.0))
        category = mapping.get(prompt)
        if category is None:
            continue

        per_category_samples[category].append(score)
        ranked_labels.append(
            {
                "name": prompt.replace(" ", "_"),
                "score": round(score, 4),
                "category": category,
                "rank": prompt_order.get(prompt, 9999),
            }
        )

    per_category: dict[str, float] = {}
    for category in labels:
        samples = sorted(per_category_samples[category], reverse=True)
        if not samples:
            per_category[category] = 0.0
            continue
        top_score = samples[0]
        per_category[category] = max(0.0, min(1.0, top_score * MODEL_CONFIDENCE_BIAS))

    best_category = max(per_category, key=per_category.get)
    best_score = per_category[best_category]

    ranked_labels.sort(key=lambda entry: (entry["score"], -entry["rank"]), reverse=True)

    return {
        "category": best_category,
        "confidence": round(best_score, 4),
        "model": DEFAULT_MODEL,
        "algorithm_version": ALGORITHM_VERSION,
        "labels": ranked_labels[:5],
    }


@app.post("/face-signature")
async def face_signature(
    file: UploadFile = File(...),
    authorization: str | None = Header(default=None),
) -> dict[str, Any]:
    require_authorization(authorization)

    raw = await file.read()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty file")
    if len(raw) > MAX_IMAGE_BYTES:
        raise HTTPException(status_code=413, detail="File too large")

    try:
        image = preprocess_image(raw)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"Invalid image data: {exc}") from exc

    faces = detect_faces(image)
    if len(faces) == 0:
        return {
            "has_face": False,
            "face_count": 0,
            "algorithm_version": ALGORITHM_VERSION,
        }

    signature, confidence = build_face_signature(image, faces)
    return {
        "has_face": True,
        "face_count": len(faces),
        "signature": signature,
        "confidence": confidence,
        "algorithm_version": ALGORITHM_VERSION,
    }
