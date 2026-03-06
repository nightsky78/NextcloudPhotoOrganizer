from __future__ import annotations

import base64
import os
import urllib.request
from functools import lru_cache
from io import BytesIO
from pathlib import Path
from typing import Any

import cv2
import imagehash
import numpy as np
import torch
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
EMBEDDING_SIGNATURE_PREFIX = "emb:v1:"
EMBEDDING_TARGET_DIMS = max(32, min(128, int(os.getenv("ML_WORKER_EMBEDDING_DIMS", "128"))))
YUNET_MODEL_URL = os.getenv(
    "ML_WORKER_YUNET_MODEL_URL",
    "https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx",
)
SFACE_MODEL_URL = os.getenv(
    "ML_WORKER_SFACE_MODEL_URL",
    "https://github.com/opencv/opencv_zoo/raw/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx",
)
MODEL_CACHE_DIR = os.getenv("ML_WORKER_MODEL_CACHE_DIR", "/tmp/ml_worker_models")
YUNET_SCORE_THRESHOLD = float(os.getenv("ML_WORKER_YUNET_SCORE_THRESHOLD", "0.90"))
YUNET_NMS_THRESHOLD = float(os.getenv("ML_WORKER_YUNET_NMS_THRESHOLD", "0.30"))
YUNET_TOP_K = int(os.getenv("ML_WORKER_YUNET_TOP_K", "5000"))
MIN_FACE_SIZE = max(24, int(os.getenv("ML_WORKER_MIN_FACE_SIZE", "48")))

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


def _download_model_if_missing(url: str, target: Path) -> Path:
    if target.exists() and target.stat().st_size > 0:
        return target

    target.parent.mkdir(parents=True, exist_ok=True)
    temp_target = target.with_suffix(target.suffix + ".tmp")
    with urllib.request.urlopen(url, timeout=45) as response, temp_target.open("wb") as handle:
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            handle.write(chunk)

    os.replace(temp_target, target)
    return target


@lru_cache(maxsize=1)
def get_yunet_detector() -> Any | None:
    if not hasattr(cv2, "FaceDetectorYN_create"):
        return None

    model_path = Path(MODEL_CACHE_DIR) / "face_detection_yunet_2023mar.onnx"
    try:
        resolved_model = _download_model_if_missing(YUNET_MODEL_URL, model_path)
        detector = cv2.FaceDetectorYN_create(
            str(resolved_model),
            "",
            (320, 320),
            YUNET_SCORE_THRESHOLD,
            YUNET_NMS_THRESHOLD,
            YUNET_TOP_K,
        )
        return detector
    except Exception:
        return None


@lru_cache(maxsize=1)
def get_sface_recognizer() -> Any | None:
    if not hasattr(cv2, "FaceRecognizerSF_create"):
        return None

    model_path = Path(MODEL_CACHE_DIR) / "face_recognition_sface_2021dec.onnx"
    try:
        resolved_model = _download_model_if_missing(SFACE_MODEL_URL, model_path)
        recognizer = cv2.FaceRecognizerSF_create(str(resolved_model), "")
        return recognizer
    except Exception:
        return None


def _detect_faces_with_yunet(image: Image.Image) -> list[dict[str, Any]]:
    detector = get_yunet_detector()
    if detector is None:
        return []

    rgb = np.array(image)
    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
    height, width = bgr.shape[:2]
    detector.setInputSize((width, height))

    _, raw_faces = detector.detect(bgr)
    if raw_faces is None or len(raw_faces) == 0:
        return []

    detected: list[dict[str, Any]] = []
    for row in raw_faces:
        x, y, w, h = row[:4]
        ix = max(0, int(round(float(x))))
        iy = max(0, int(round(float(y))))
        iw = int(round(float(w)))
        ih = int(round(float(h)))
        if iw < MIN_FACE_SIZE or ih < MIN_FACE_SIZE:
            continue

        iw = min(iw, width - ix)
        ih = min(ih, height - iy)
        if iw <= 0 or ih <= 0:
            continue

        detected.append(
            {
                "box": (ix, iy, iw, ih),
                "score": float(row[14]) if len(row) > 14 else None,
                "raw": row.astype(np.float32),
            }
        )

    if len(detected) <= 1:
        return detected

    ordered = sorted(detected, key=lambda item: item["box"][2] * item["box"][3], reverse=True)
    filtered: list[dict[str, Any]] = []
    for candidate in ordered:
        if all(_box_iou(candidate["box"], existing["box"]) < 0.35 for existing in filtered):
            filtered.append(candidate)

    return filtered


def detect_faces(image: Image.Image) -> list[dict[str, Any]]:
    try:
        yunet_faces = _detect_faces_with_yunet(image)
        if yunet_faces:
            return yunet_faces
    except Exception:
        pass

    rgb = np.array(image)
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    cascade = get_face_cascade()
    faces = cascade.detectMultiScale(
        gray,
        scaleFactor=1.15,
        minNeighbors=6,
        minSize=(56, 56),
    )
    detected = [
        {
            "box": tuple(map(int, face)),
            "score": None,
            "raw": None,
        }
        for face in faces
    ]
    if len(detected) <= 1:
        return detected

    ordered = sorted(detected, key=lambda item: item["box"][2] * item["box"][3], reverse=True)
    filtered: list[dict[str, Any]] = []
    for candidate in ordered:
        if all(_box_iou(candidate["box"], existing["box"]) < 0.35 for existing in filtered):
            filtered.append(candidate)

    return filtered


def _box_iou(a: tuple[int, int, int, int], b: tuple[int, int, int, int]) -> float:
    ax, ay, aw, ah = a
    bx, by, bw, bh = b

    left = max(ax, bx)
    top = max(ay, by)
    right = min(ax + aw, bx + bw)
    bottom = min(ay + ah, by + bh)

    inter_w = max(0, right - left)
    inter_h = max(0, bottom - top)
    inter_area = inter_w * inter_h
    if inter_area == 0:
        return 0.0

    area_a = aw * ah
    area_b = bw * bh
    union = area_a + area_b - inter_area
    if union <= 0:
        return 0.0

    return inter_area / union


def _to_base64_urlsafe(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).decode("ascii").rstrip("=")


def _compress_embedding(embedding: np.ndarray, target_dims: int) -> np.ndarray:
    vector = embedding.astype(np.float32).reshape(-1)
    length = vector.shape[0]
    if length <= target_dims:
        return vector

    chunks = np.array_split(vector, target_dims)
    compressed = np.array([float(np.mean(chunk)) for chunk in chunks], dtype=np.float32)
    return compressed


def _embedding_signature_from_vector(embedding: np.ndarray) -> str | None:
    vector = embedding.astype(np.float32).reshape(-1)
    if vector.size == 0:
        return None

    vector = _compress_embedding(vector, EMBEDDING_TARGET_DIMS)
    norm = float(np.linalg.norm(vector))
    if norm <= 0.0:
        return None

    normalized = vector / norm
    quantized = np.clip(np.rint(normalized * 127.0), -127, 127).astype(np.int8)
    return EMBEDDING_SIGNATURE_PREFIX + _to_base64_urlsafe(quantized.tobytes())


def _face_embedding_signature(crop: Image.Image) -> str | None:
    classifier = get_classifier()
    model = getattr(classifier, "model", None)
    processor = getattr(classifier, "image_processor", None)
    if model is None or processor is None or not hasattr(model, "get_image_features"):
        return None

    try:
        inputs = processor(images=crop, return_tensors="pt")
        with torch.no_grad():
            image_features = model.get_image_features(**inputs)

        embedding = image_features[0].detach().cpu().numpy().astype(np.float32)
        return _embedding_signature_from_vector(embedding)
    except Exception:
        return None


def _sface_embedding_signature(image: Image.Image, raw_face: np.ndarray | None) -> str | None:
    if raw_face is None:
        return None

    recognizer = get_sface_recognizer()
    if recognizer is None:
        return None

    try:
        bgr = cv2.cvtColor(np.array(image), cv2.COLOR_RGB2BGR)
        aligned = recognizer.alignCrop(bgr, raw_face)
        feature = recognizer.feature(aligned)
        if feature is None:
            return None
        return _embedding_signature_from_vector(feature)
    except Exception:
        return None


def _build_single_face_signature(
    image: Image.Image,
    face: dict[str, Any],
    total_faces: int,
) -> tuple[str, float]:
    box = face["box"]
    detection_score = face.get("score")
    raw_face = face.get("raw")

    signature = _sface_embedding_signature(image, raw_face)
    if signature is None:
        x, y, w, h = box
        crop_rgb = image.crop((x, y, x + w, y + h)).convert("RGB")
        signature = _face_embedding_signature(crop_rgb)
        if signature is None:
            signature = str(imagehash.phash(crop_rgb.convert("L"), hash_size=16))

    x, y, w, h = box

    area_ratio = float((w * h) / max(1, image.width * image.height))
    base_confidence = 0.5 + (area_ratio * 2.0) + (total_faces * 0.05)
    if detection_score is not None:
        base_confidence = (base_confidence * 0.6) + (float(detection_score) * 0.4)
    confidence = min(1.0, max(0.3, base_confidence))
    return signature, round(confidence, 4)


def build_face_signatures(
    image: Image.Image,
    faces: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    ordered_faces = sorted(faces, key=lambda item: item["box"][2] * item["box"][3], reverse=True)
    signatures: list[dict[str, Any]] = []
    for index, face in enumerate(ordered_faces, start=1):
        signature, confidence = _build_single_face_signature(image, face, len(ordered_faces))
        signatures.append(
            {
                "face_index": index,
                "signature": signature,
                "confidence": confidence,
            }
        )

    return signatures


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

    signatures = build_face_signatures(image, faces)
    primary = signatures[0]
    return {
        "has_face": True,
        "face_count": len(faces),
        "signature": primary["signature"],
        "confidence": primary["confidence"],
        "faces": signatures,
        "algorithm_version": ALGORITHM_VERSION,
    }
