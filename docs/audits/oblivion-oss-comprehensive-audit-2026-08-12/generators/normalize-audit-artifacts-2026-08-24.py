#!/usr/bin/env python3
"""Remove machine-local path prefixes and normalize mislabeled screenshots.

This is an audit-artifact hygiene pass only. It does not change application
source, finding dispositions, benchmark credit, or completion-gate status.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
from datetime import datetime
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / "evidence" / "source" / "artifact-hygiene-2026-08-24.json"
TEXT_SUFFIXES = {".csv", ".html", ".json", ".md", ".mjs", ".ps1", ".py", ".txt"}
LOCAL_USER_PREFIX = re.compile(
    r"C:[\\/]+Users[\\/]+[^\\/\s\"'<>]+[\\/]+",
    re.IGNORECASE,
)


def relative(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def pixel_digest(image: Image.Image) -> str:
    normalized = image.convert("RGBA")
    return hashlib.sha256(normalized.tobytes()).hexdigest()


def sanitize_machine_paths() -> tuple[int, list[str]]:
    replacements = 0
    changed: list[str] = []

    for path in sorted(ROOT.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue

        text = path.read_text(encoding="utf-8")
        rewritten, count = LOCAL_USER_PREFIX.subn("<local-user>/", text)
        if not count:
            continue

        path.write_text(rewritten, encoding="utf-8", newline="")
        replacements += count
        changed.append(relative(path))

    return replacements, changed


def normalize_png_payloads() -> list[dict[str, object]]:
    converted: list[dict[str, object]] = []

    for path in sorted(ROOT.rglob("*.png")):
        with path.open("rb") as handle:
            magic = handle.read(3)
        if magic != b"\xff\xd8\xff":
            continue

        with Image.open(path) as source:
            source.load()
            width, height = source.size
            before = pixel_digest(source)
            normalized = source.convert("RGB")

        temporary = path.with_suffix(path.suffix + ".tmp")
        normalized.save(temporary, format="PNG", optimize=True)

        with Image.open(temporary) as check:
            check.load()
            after = pixel_digest(check)
            if check.format != "PNG" or check.size != (width, height) or before != after:
                temporary.unlink(missing_ok=True)
                raise RuntimeError(f"Pixel verification failed for {relative(path)}")

        os.replace(temporary, path)
        converted.append(
            {
                "path": relative(path),
                "width": width,
                "height": height,
                "pixel_sha256": after,
            }
        )

    return converted


def count_local_paths() -> int:
    count = 0
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue
        count += len(LOCAL_USER_PREFIX.findall(path.read_text(encoding="utf-8")))
    return count


def main() -> None:
    previous = {}
    if EVIDENCE.exists():
        previous = json.loads(EVIDENCE.read_text(encoding="utf-8"))

    replacements, changed_text_files = sanitize_machine_paths()
    converted_images = normalize_png_payloads()
    previous_images = previous.get("normalized_images", [])
    images_by_path = {
        row["path"]: row
        for row in [*previous_images, *converted_images]
    }
    all_converted_images = [images_by_path[path] for path in sorted(images_by_path)]
    all_changed_text_files = sorted(
        set(previous.get("machine_path_files", [])) | set(changed_text_files)
    )
    total_replacements = previous.get("machine_path_replacements", 0) + replacements
    remaining_jpeg_named_png = []
    for path in ROOT.rglob("*.png"):
        with path.open("rb") as handle:
            if handle.read(3) == b"\xff\xd8\xff":
                remaining_jpeg_named_png.append(relative(path))

    pyc_files = [relative(path) for path in ROOT.rglob("*.pyc")]
    report = {
        "schema_version": "1.0",
        "generated_at": datetime.now().astimezone().isoformat(timespec="seconds"),
        "scope": "Audit artifacts only; no application source or audit-credit mutation.",
        "machine_path_replacements": total_replacements,
        "machine_path_files": all_changed_text_files,
        "remaining_machine_local_paths": count_local_paths(),
        "normalized_jpeg_named_png_count": len(all_converted_images),
        "normalized_images": all_converted_images,
        "remaining_jpeg_named_png": remaining_jpeg_named_png,
        "remaining_pyc_files": pyc_files,
        "status": (
            "PASS"
            if count_local_paths() == 0 and not remaining_jpeg_named_png and not pyc_files
            else "FAIL"
        ),
    }
    EVIDENCE.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({key: report[key] for key in (
        "machine_path_replacements",
        "remaining_machine_local_paths",
        "normalized_jpeg_named_png_count",
        "remaining_jpeg_named_png",
        "remaining_pyc_files",
        "status",
    )}, indent=2))


if __name__ == "__main__":
    main()
