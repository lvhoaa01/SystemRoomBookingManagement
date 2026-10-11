from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import pathlib
import re
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parent
MANIFEST = ROOT / "MANIFEST.csv"
DEFAULT_OUT = ROOT / "images"
LOCK_NAME = "ASSET_LOCK.json"
USER_AGENT = "Mozilla/5.0 (compatible; MiniOTA-ImageDownloader/2.1; educational project)"
ALLOWED_INITIAL_HOSTS = {"commons.wikimedia.org"}
ALLOWED_FINAL_HOSTS = {"commons.wikimedia.org", "upload.wikimedia.org", "thumb.wikimedia.org"}
ALLOWED_MIME = {"image/jpeg", "image/png"}
MAX_BYTES = 25 * 1024 * 1024
MIN_BYTES = 10_000
MIN_EDGE = 480
DOWNLOAD_DELAY_SECONDS = 1.0
MAX_DOWNLOAD_ATTEMPTS = 3
REQUEST_TIMEOUT_SECONDS = 30
SAFE_FILE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png)$", re.IGNORECASE)
REQUIRED_COLUMNS = {
    "local_file",
    "group",
    "recommended_use",
    "original_file",
    "author",
    "license",
    "license_url",
    "source_page",
    "direct_url",
    "changes",
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Tải và kiểm tra ảnh Hotel Majestic Saigon.")
    parser.add_argument("--output", type=pathlib.Path, default=DEFAULT_OUT)
    parser.add_argument("--force", action="store_true", help="Tải lại cả file đã hợp lệ.")
    return parser.parse_args()


def validate_https_url(value: str, allowed_hosts: set[str], field: str) -> None:
    parsed = urllib.parse.urlparse(value)
    if parsed.scheme != "https" or parsed.hostname not in allowed_hosts:
        raise ValueError(f"{field} phải là URL HTTPS Wikimedia hợp lệ: {value}")


def safe_target(output: pathlib.Path, local_file: str) -> pathlib.Path:
    if not SAFE_FILE.fullmatch(local_file) or pathlib.PurePath(local_file).name != local_file:
        raise ValueError(f"Tên file không an toàn: {local_file}")
    target = (output / local_file).resolve()
    if target.parent != output.resolve():
        raise ValueError(f"File thoát khỏi thư mục đích: {local_file}")
    return target


def thumbnail_url(original_file: str) -> str:
    """Tạo URL thumbnail chuẩn để không tải file gốc dung lượng lớn qua redirect."""
    normalized = original_file.replace(" ", "_")
    if not normalized or "/" in normalized or "\\" in normalized:
        raise ValueError(f"Tên file Wikimedia không hợp lệ: {original_file}")
    digest = hashlib.md5(normalized.encode("utf-8")).hexdigest()
    encoded = urllib.parse.quote(normalized, safe="()_,.-")
    return (
        "https://thumb.wikimedia.org/wikipedia/commons/thumb/"
        f"{digest[0]}/{digest[:2]}/{encoded}/960px-{encoded}"
    )


def jpeg_dimensions(path: pathlib.Path) -> tuple[int, int]:
    sof_markers = {0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF}
    with path.open("rb") as stream:
        if stream.read(2) != b"\xff\xd8":
            raise ValueError("JPEG thiếu SOI marker")
        while True:
            byte = stream.read(1)
            if not byte:
                break
            if byte != b"\xff":
                continue
            marker = stream.read(1)
            while marker == b"\xff":
                marker = stream.read(1)
            if not marker:
                break
            marker_value = marker[0]
            if marker_value in {0x01, 0xD8, 0xD9} or 0xD0 <= marker_value <= 0xD7:
                continue
            raw_length = stream.read(2)
            if len(raw_length) != 2:
                break
            segment_length = struct.unpack(">H", raw_length)[0]
            if segment_length < 2:
                raise ValueError("JPEG segment không hợp lệ")
            if marker_value in sof_markers:
                payload = stream.read(5)
                if len(payload) != 5:
                    break
                height, width = struct.unpack(">HH", payload[1:5])
                return width, height
            stream.seek(segment_length - 2, os.SEEK_CUR)
    raise ValueError("Không đọc được kích thước JPEG")


def inspect_image(path: pathlib.Path) -> dict[str, object]:
    size = path.stat().st_size
    if size < MIN_BYTES or size > MAX_BYTES:
        raise ValueError(f"Dung lượng ngoài giới hạn: {size} bytes")
    with path.open("rb") as stream:
        signature = stream.read(24)
    if signature.startswith(b"\xff\xd8\xff"):
        mime = "image/jpeg"
        width, height = jpeg_dimensions(path)
    elif signature.startswith(b"\x89PNG\r\n\x1a\n") and len(signature) >= 24:
        mime = "image/png"
        width, height = struct.unpack(">II", signature[16:24])
    else:
        raise ValueError("Chữ ký file không phải JPEG/PNG")
    if min(width, height) < MIN_EDGE:
        raise ValueError(f"Ảnh quá nhỏ: {width}x{height}")
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    return {"bytes": size, "mime": mime, "width": width, "height": height, "sha256": digest}


def download(url: str, target: pathlib.Path) -> dict[str, object]:
    partial = target.with_name(target.name + ".part")
    if partial.exists():
        partial.unlink()
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        response = None
        for attempt in range(1, MAX_DOWNLOAD_ATTEMPTS + 1):
            try:
                response = urllib.request.urlopen(request, timeout=REQUEST_TIMEOUT_SECONDS)
                break
            except urllib.error.HTTPError as exc:
                if exc.code not in {429, 503} or attempt == MAX_DOWNLOAD_ATTEMPTS:
                    raise
                retry_after = exc.headers.get("Retry-After", "")
                server_wait = int(retry_after) if retry_after.isdigit() else 2 ** attempt
                wait_seconds = min(server_wait, 4)
                time.sleep(wait_seconds)
        if response is None:
            raise RuntimeError("Không mở được kết nối tải ảnh.")
        with response, partial.open("wb") as output:
            final_url = response.geturl()
            validate_https_url(final_url, ALLOWED_FINAL_HOSTS, "URL sau redirect")
            content_type = response.headers.get_content_type().lower()
            if content_type not in ALLOWED_MIME:
                raise ValueError(f"MIME không hỗ trợ: {content_type}")
            total = 0
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > MAX_BYTES:
                    raise ValueError(f"File vượt {MAX_BYTES} bytes")
                output.write(chunk)
        metadata = inspect_image(partial)
        if metadata["mime"] != content_type:
            raise ValueError(f"MIME header {content_type} không khớp nội dung {metadata['mime']}")
        os.replace(partial, target)
        return metadata
    finally:
        if partial.exists():
            partial.unlink()


def write_lock(output: pathlib.Path, entries: list[dict[str, object]]) -> None:
    lock_path = output / LOCK_NAME
    partial = output / (LOCK_NAME + ".part")
    payload = {
        "version": 1,
        "sourceManifest": "huongDan/majestic_hotel_image_pack_v2/MANIFEST.csv",
        "files": entries,
    }
    partial.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.replace(partial, lock_path)


def main() -> int:
    args = parse_args()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)

    with MANIFEST.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None or set(reader.fieldnames) != REQUIRED_COLUMNS:
            raise RuntimeError("Cấu trúc MANIFEST.csv không đúng phiên bản được hỗ trợ.")
        rows = list(reader)
    if len(rows) != 15:
        raise RuntimeError(f"Manifest phải có đúng 15 ảnh, hiện có {len(rows)}.")

    seen: set[str] = set()
    failures: list[tuple[str, str, str]] = []
    lock_entries: list[dict[str, object]] = []
    print(f"Hotel Majestic Saigon Image Pack v2 - {len(rows)} images\n")

    for index, row in enumerate(rows, 1):
        name = row["local_file"]
        print(f"[{index:02d}/{len(rows):02d}] {name} | {row['group']}")
        try:
            if name in seen:
                raise ValueError(f"Tên file trùng: {name}")
            seen.add(name)
            validate_https_url(row["source_page"], ALLOWED_INITIAL_HOSTS, "source_page")
            validate_https_url(row["direct_url"], ALLOWED_INITIAL_HOSTS, "direct_url")
            validate_https_url(row["license_url"], {"creativecommons.org"}, "license_url")
            target = safe_target(output, name)
            if target.exists() and not args.force:
                metadata = inspect_image(target)
                print(f"      KEEP - {metadata['width']}x{metadata['height']} - {metadata['bytes'] / 1024:.1f} KiB")
            else:
                metadata = download(thumbnail_url(row["original_file"]), target)
                print(f"      OK   - {metadata['width']}x{metadata['height']} - {metadata['bytes'] / 1024:.1f} KiB")
                time.sleep(DOWNLOAD_DELAY_SECONDS)
            lock_entries.append(
                {
                    "localFile": name,
                    "group": row["group"],
                    "author": row["author"],
                    "license": row["license"],
                    "licenseUrl": row["license_url"],
                    "sourcePage": row["source_page"],
                    "changes": row["changes"],
                    **metadata,
                }
            )
        except Exception as exc:
            failures.append((name, str(exc), row.get("source_page", "")))
            print(f"      FAILED - {exc}")

    print()
    if failures:
        print(f"Failed: {len(failures)} file(s)")
        for name, error, page in failures:
            print(f"- {name}: {error}\n  source: {page}")
        return 1

    write_lock(output, lock_entries)
    print(f"Done. Verified {len(lock_entries)} images in: {output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
