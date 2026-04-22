from __future__ import annotations

import difflib
import json
import os
import re
import time
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone, tzinfo
from io import BytesIO
from typing import Any, Callable, Iterable
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from google import genai
from PIL import Image


MODEL = "models/gemini-3.1-flash-lite-preview"
REQUESTS_PER_MINUTE = 13
DEFAULT_DAILY_REQUEST_BUDGET = 300
INITIAL_ATTEMPTS = 1
FINAL_RETRY_ATTEMPTS = 1
SERVICE_UNAVAILABLE_THRESHOLD = 2
SERVICE_UNAVAILABLE_COOLDOWN_MINUTES = 10
LATE_SERVICE_UNAVAILABLE_COOLDOWN_MINUTES = 5
MAX_SERVICE_UNAVAILABLE_SKIPS_BEFORE_STOP = 2
SERVICE_UNAVAILABLE_SKIP_DELAY_MINUTES = 2
SERVICE_UNAVAILABLE_CANCEL_CHECK_SECONDS = 5

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
API_KEY_PATH = os.path.join(BASE_DIR, "api.txt")
CITIES_MAP_PATH = os.path.join(BASE_DIR, "cities", "final_pincode_map.json")

PROMPT = """Extract recipient details from this courier parcel image.

The image may be rotated. Consider all orientations.

Extraction priority:
1. Barcode/printed shipping label (for AWB)
2. Printed recipient details
3. Handwritten recipient details (use only if printed is missing or unclear)

Rules:
- Extract ONLY recipient/delivery details (ignore sender details completely)
- If a handwritten line starts with "Name", use it as recipient name
- Do not mix sender and recipient information

Return ONLY valid minified JSON:
{"name":"","address":"","pin":"","phone":"","awb":""}
"""

EXTRACTED_FIELDS = ["receiver", "address", "pin", "phone", "awb"]
OUTPUT_FIELDS = ["receiver", "address", "city", "pin", "phone", "awb"]
REQUEST_INTERVAL = 60 / REQUESTS_PER_MINUTE
StatusCallback = Callable[[int, str], None]
CancellationChecker = Callable[[], bool]


@dataclass
class UploadedImage:
    file_name: str
    source_path: str
    sender: str
    image_bytes: bytes


@dataclass
class BatchProcessingResult:
    rows: list[dict[str, str]]
    leftover_files: list[str]
    usage: dict[str, Any]
    message: str = "Processing complete. Download actions are ready."


class JobCanceledError(RuntimeError):
    pass


class ServiceUnavailableAfterCooldownError(RuntimeError):
    pass


def load_api_key() -> str:
    env_key = os.getenv("GEMINI_API_KEY", "").strip()
    if env_key:
        return env_key

    if not os.path.exists(API_KEY_PATH):
        raise FileNotFoundError(
            f"API key file not found: {API_KEY_PATH}. Paste your API key into api.txt or set GEMINI_API_KEY."
        )

    with open(API_KEY_PATH, "r", encoding="utf-8") as file_handle:
        for line in file_handle:
            candidate = line.strip()

            if not candidate or candidate.startswith("#"):
                continue

            if "=" in candidate:
                candidate = candidate.split("=", 1)[1].strip()

            candidate = candidate.strip().strip('"').strip("'")
            if candidate:
                return candidate

    raise ValueError(f"No API key found in {API_KEY_PATH}.")


def get_app_timezone() -> tzinfo:
    timezone_name = os.getenv("APP_TIMEZONE", "Asia/Calcutta").strip() or "Asia/Calcutta"
    try:
        return ZoneInfo(timezone_name)
    except ZoneInfoNotFoundError:
        return timezone(timedelta(hours=5, minutes=30), name="Asia/Calcutta")


def current_business_date() -> str:
    return datetime.now(get_app_timezone()).date().isoformat()


def normalize_value(value: Any) -> str:
    if value is None:
        return ""

    if isinstance(value, list):
        return " ".join(normalize_value(item) for item in value if item is not None).strip()

    if isinstance(value, dict):
        return json.dumps(value, ensure_ascii=False)

    return str(value).strip()


def extract_json_block(text: str) -> dict:
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        match = re.search(r"\{.*\}", text, re.S)
        if not match:
            raise
        return json.loads(match.group(0))


def pick_pin(value: Any) -> str:
    match = re.search(r"(?<!\d)(\d{6})(?!\d)", normalize_value(value))
    return match.group(1) if match else ""


def pick_phone(value: Any) -> str:
    matches = re.findall(r"(?<!\d)(?:91[\s\-]*)?([6-9]\d{9})(?!\d)", normalize_value(value))
    return matches[0] if matches else ""


def pick_awb(value: Any) -> str:
    match = re.search(r"(?<!\d)(\d{12})(?!\d)", normalize_value(value))
    return match.group(1) if match else ""


def normalize_match_text(value: Any) -> str:
    text = normalize_value(value).lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def build_address_phrases(address: str, max_words: int) -> list[tuple[str, int]]:
    normalized_address = normalize_match_text(address)
    tokens = normalized_address.split()
    if not tokens:
        return []

    phrases: dict[str, int] = {}

    for start in range(len(tokens)):
        max_length = min(max_words, len(tokens) - start)
        for length in range(1, max_length + 1):
            phrase = " ".join(tokens[start : start + length])
            end_position = start + length
            if phrase not in phrases or end_position > phrases[phrase]:
                phrases[phrase] = end_position

    return list(phrases.items())


def fuzzy_threshold(candidate_text: str) -> float:
    compact = candidate_text.replace(" ", "")
    length = len(compact)

    if length <= 4:
        return 0.97
    if length <= 6:
        return 0.94
    if length <= 8:
        return 0.91
    return 0.88


def find_best_location_match(address: str, candidates: Iterable[str]) -> str:
    normalized_candidates: list[tuple[int, str, str]] = []
    max_words = 1

    for index, candidate in enumerate(candidates):
        candidate_norm = normalize_match_text(candidate)
        if not candidate_norm:
            continue

        normalized_candidates.append((index, candidate, candidate_norm))
        max_words = max(max_words, len(candidate_norm.split()))

    if not normalized_candidates:
        return ""

    phrases = build_address_phrases(address, max_words)
    if not phrases:
        return ""

    phrase_positions = {phrase: position for phrase, position in phrases}

    best_exact: tuple[tuple[int, int, int], str] | None = None
    for index, candidate, candidate_norm in normalized_candidates:
        if candidate_norm not in phrase_positions:
            continue

        score = (
            phrase_positions[candidate_norm],
            len(candidate_norm.replace(" ", "")),
            -index,
        )
        if best_exact is None or score > best_exact[0]:
            best_exact = (score, candidate)

    if best_exact:
        return best_exact[1]

    best_fuzzy: tuple[tuple[float, int, int, int], str] | None = None
    for index, candidate, candidate_norm in normalized_candidates:
        compact_candidate = candidate_norm.replace(" ", "")
        if len(compact_candidate) < 4:
            continue

        candidate_words = len(candidate_norm.split())
        prefix_length = min(3, len(compact_candidate))
        threshold = fuzzy_threshold(candidate_norm)

        for phrase, position in phrases:
            compact_phrase = phrase.replace(" ", "")
            if len(compact_phrase) < 4:
                continue
            if abs(len(phrase.split()) - candidate_words) > 1:
                continue
            if compact_candidate[:prefix_length] != compact_phrase[:prefix_length]:
                continue

            ratio = difflib.SequenceMatcher(None, candidate_norm, phrase).ratio()
            if ratio < threshold:
                continue

            score = (
                ratio,
                position,
                len(compact_candidate),
                -index,
            )
            if best_fuzzy is None or score > best_fuzzy[0]:
                best_fuzzy = (score, candidate)

    return best_fuzzy[1] if best_fuzzy else ""


def load_city_map() -> dict[str, list[str]]:
    if not os.path.exists(CITIES_MAP_PATH):
        return {}

    with open(CITIES_MAP_PATH, "r", encoding="utf-8") as file_handle:
        raw_data = json.load(file_handle)

    if not isinstance(raw_data, dict):
        return {}

    city_map: dict[str, list[str]] = {}

    for raw_pin, raw_names in raw_data.items():
        pin = pick_pin(raw_pin)
        if not pin or not isinstance(raw_names, list):
            continue

        cleaned_names: list[str] = []
        seen: set[str] = set()

        for raw_name in raw_names:
            name = normalize_value(raw_name)
            if not name:
                continue

            name_key = name.lower()
            if name_key in seen:
                continue

            seen.add(name_key)
            cleaned_names.append(name)

        if cleaned_names:
            city_map[pin] = cleaned_names

    return city_map


def should_count_request_error(error: Exception) -> bool:
    text = str(error).upper()
    non_count_errors = [
        "GETADDRINFO FAILED",
        "NAME OR SERVICE NOT KNOWN",
        "TEMPORARY FAILURE IN NAME RESOLUTION",
        "FAILED TO ESTABLISH A NEW CONNECTION",
        "MAX RETRIES EXCEEDED",
        "CONNECTION ABORTED",
        "CONNECTION RESET",
        "CONNECTION REFUSED",
        "NO ADDRESS ASSOCIATED WITH HOSTNAME",
        "NETWORK IS UNREACHABLE",
    ]
    return not any(pattern in text for pattern in non_count_errors)


def is_service_unavailable_error(error: Exception) -> bool:
    text_upper = str(error).upper()
    return ("503" in text_upper and "UNAVAILABLE" in text_upper) or "HIGH DEMAND" in text_upper


class ParcelExtractor:
    def __init__(self, usage_store: Any) -> None:
        self.usage_store = usage_store
        self.run_date = current_business_date()
        self.client = genai.Client(api_key=load_api_key())
        self.last_request_time = 0.0
        self.city_map = load_city_map()
        self.usage_state = usage_store.load_usage_state(self.run_date, DEFAULT_DAILY_REQUEST_BUDGET)
        self.daily_request_budget = int(self.usage_state["daily_budget"])
        self.requests_used = int(self.usage_state["requests_used"])

    def current_usage_snapshot(self) -> dict[str, Any]:
        return {
            "date": self.run_date,
            "daily_budget": self.daily_request_budget,
            "requests_used": self.requests_used,
            "requests_remaining": max(self.daily_request_budget - self.requests_used, 0),
        }

    def remaining_request_budget(self) -> int:
        return max(self.daily_request_budget - self.requests_used, 0)

    def record_request_hit(self) -> None:
        self.requests_used += 1
        self.usage_state = self.current_usage_snapshot()
        self.usage_store.save_usage_state(self.usage_state)

    def throttle_request(self) -> None:
        if self.last_request_time:
            elapsed = time.time() - self.last_request_time
            if elapsed < REQUEST_INTERVAL:
                time.sleep(REQUEST_INTERVAL - elapsed)

        self.last_request_time = time.time()

    def empty_data(self, file_name: str = "", sender: str = "", source_path: str = "") -> dict[str, str]:
        return {
            "date": self.run_date,
            "file": file_name,
            "source_path": source_path or file_name,
            "sender": sender,
            "receiver": "",
            "address": "",
            "city": "",
            "pin": "",
            "phone": "",
            "awb": "",
        }

    def infer_city(self, address: str, pin: str) -> str:
        pin_code = pick_pin(pin)
        if not pin_code:
            return ""

        address_text = normalize_value(address)
        if not address_text:
            return ""

        location_list = self.city_map.get(pin_code, [])
        if not location_list:
            return ""

        district = location_list[0]
        city_list = location_list[1:]

        city_match = find_best_location_match(address_text, city_list)
        if city_match:
            return city_match

        district_match = find_best_location_match(address_text, [district])
        if district_match:
            return district_match

        return ""

    def validate_json(self, text: str, file_name: str, sender: str, source_path: str) -> dict[str, str]:
        try:
            data = extract_json_block(text)
            if not isinstance(data, dict):
                return self.empty_data(file_name, sender, source_path)

            validated = {
                "date": self.run_date,
                "file": file_name,
                "source_path": source_path,
                "sender": sender,
                "receiver": normalize_value(data.get("receiver", "") or data.get("name", "")),
                "address": normalize_value(data.get("address", "")),
                "city": "",
                "pin": normalize_value(data.get("pin", "")),
                "phone": normalize_value(data.get("phone", "")),
                "awb": normalize_value(data.get("awb", "")),
            }
            validated["pin"] = pick_pin(validated["pin"])
            validated["phone"] = pick_phone(validated["phone"])
            validated["awb"] = pick_awb(validated["awb"])
            return validated
        except Exception:
            return {
                "date": self.run_date,
                "file": file_name,
                "source_path": source_path,
                "sender": sender,
                "receiver": "",
                "address": "",
                "city": "",
                "pin": pick_pin(text),
                "phone": pick_phone(text),
                "awb": pick_awb(text),
            }

    def merge_data(
        self,
        current_data: dict[str, str],
        new_data: dict[str, str],
        file_name: str,
        sender: str,
        source_path: str,
    ) -> dict[str, str]:
        merged = {
            "date": current_data.get("date") or new_data.get("date", "") or self.run_date,
            "file": current_data.get("file") or new_data.get("file", "") or file_name,
            "source_path": current_data.get("source_path") or new_data.get("source_path", "") or source_path,
            "sender": current_data.get("sender") or new_data.get("sender", "") or sender,
            "receiver": "",
            "address": "",
            "city": current_data.get("city") or new_data.get("city", ""),
        }

        for key in EXTRACTED_FIELDS:
            merged[key] = current_data.get(key, "") or new_data.get(key, "")

        return merged

    @staticmethod
    def has_missing_fields(data: dict[str, str]) -> bool:
        return any(not data.get(key, "") for key in EXTRACTED_FIELDS)

    @staticmethod
    def retry_priority(item: dict[str, str]) -> int:
        filled_count = sum(1 for key in EXTRACTED_FIELDS if item.get(key))
        score = 0

        if item.get("address") and not item.get("awb"):
            score += 50
        if item.get("awb") and not item.get("address"):
            score += 45
        if item.get("address") or item.get("awb"):
            score += 20
        if not item.get("phone"):
            score += 5
        if not item.get("pin"):
            score += 4
        if not item.get("receiver"):
            score += 3

        return score + (filled_count * 2)

    def add_city_to_result(self, item: dict[str, str]) -> dict[str, str]:
        enriched = item.copy()
        enriched["city"] = self.infer_city(enriched.get("address", ""), enriched.get("pin", ""))
        return enriched

    def run_request(self, image: Image.Image, file_name: str, sender: str, source_path: str) -> dict[str, str]:
        if self.remaining_request_budget() <= 0:
            raise RuntimeError(
                f"Daily request budget reached ({self.daily_request_budget}). Stopping further Gemini calls for today."
            )

        self.throttle_request()

        try:
            response = self.client.models.generate_content(
                model=MODEL,
                contents=[PROMPT, image],
            )
        except Exception as exc:
            if should_count_request_error(exc):
                self.record_request_hit()
            raise

        self.record_request_hit()
        return self.validate_json((response.text or "").strip(), file_name, sender, source_path)

    @staticmethod
    def prepare_image(image_bytes: bytes) -> Image.Image:
        image = Image.open(BytesIO(image_bytes))
        image.thumbnail((1024, 1024))
        return image

    def process_single_image(
        self,
        uploaded: UploadedImage,
        attempts: int,
        continue_for_missing: bool = False,
        current_data: dict[str, str] | None = None,
        cancellation_checker: CancellationChecker | None = None,
    ) -> dict[str, str]:
        best_data = current_data.copy() if current_data else self.empty_data(
            uploaded.file_name,
            uploaded.sender,
            uploaded.source_path,
        )

        for _attempt in range(attempts):
            self._ensure_not_canceled(cancellation_checker)
            image = self.prepare_image(uploaded.image_bytes)
            try:
                new_data = self.run_request(image, uploaded.file_name, uploaded.sender, uploaded.source_path)
            finally:
                image.close()

            best_data = self.merge_data(
                best_data,
                new_data,
                uploaded.file_name,
                uploaded.sender,
                uploaded.source_path,
            )

            if continue_for_missing:
                if not self.has_missing_fields(best_data):
                    break
            else:
                if best_data["address"] or best_data["awb"]:
                    break

        return best_data

    def process_file_with_backoff(
        self,
        uploaded: UploadedImage,
        attempts: int,
        continue_for_missing: bool = False,
        current_data: dict[str, str] | None = None,
        status_callback: StatusCallback | None = None,
        progress_hint: int | None = None,
        cancellation_checker: CancellationChecker | None = None,
        cooldown_minutes: int = SERVICE_UNAVAILABLE_COOLDOWN_MINUTES,
    ) -> dict[str, str]:
        consecutive_service_unavailable = 0
        cooldown_used = False

        while True:
            self._ensure_not_canceled(cancellation_checker)
            try:
                return self.process_single_image(
                    uploaded,
                    attempts,
                    continue_for_missing=continue_for_missing,
                    current_data=current_data,
                    cancellation_checker=cancellation_checker,
                )
            except Exception as exc:
                if isinstance(exc, JobCanceledError):
                    raise

                if not is_service_unavailable_error(exc):
                    raise

                consecutive_service_unavailable += 1
                if consecutive_service_unavailable < SERVICE_UNAVAILABLE_THRESHOLD:
                    self._emit_status(
                        status_callback,
                        progress_hint or 10,
                        f"Gemini is busy while reading {uploaded.file_name}. Retrying shortly.",
                    )
                    time.sleep(REQUEST_INTERVAL)
                    continue

                if consecutive_service_unavailable >= SERVICE_UNAVAILABLE_THRESHOLD:
                    if cooldown_used:
                        raise ServiceUnavailableAfterCooldownError(
                            f"Gemini stayed under high demand while processing {uploaded.file_name}."
                        ) from exc

                    cooldown_used = True
                    cooldown_seconds = cooldown_minutes * 60
                    remaining_seconds = cooldown_seconds
                    self._emit_status(
                        status_callback,
                        progress_hint or 10,
                        f"Gemini is under high demand while processing {uploaded.file_name}. Cooling down for {cooldown_minutes} minutes before retrying.",
                    )

                    while remaining_seconds > 0:
                        self._ensure_not_canceled(cancellation_checker)
                        sleep_seconds = min(SERVICE_UNAVAILABLE_CANCEL_CHECK_SECONDS, remaining_seconds)
                        time.sleep(sleep_seconds)
                        remaining_seconds -= sleep_seconds
                        if remaining_seconds > 0 and remaining_seconds % 60 == 0:
                            remaining_minutes = max(1, remaining_seconds // 60)
                            self._emit_status(
                                status_callback,
                                progress_hint or 10,
                                f"Gemini cooldown active for {uploaded.file_name}. Retrying in about {remaining_minutes} minute(s).",
                            )

                    consecutive_service_unavailable = 0

    def _emit_status(
        self,
        status_callback: StatusCallback | None,
        progress: int,
        message: str,
    ) -> None:
        if status_callback is not None:
            status_callback(max(0, min(progress, 100)), message)

    def _sleep_with_cancel_checks(
        self,
        seconds: int,
        cancellation_checker: CancellationChecker | None = None,
    ) -> None:
        remaining_seconds = max(0, seconds)
        while remaining_seconds > 0:
            self._ensure_not_canceled(cancellation_checker)
            sleep_seconds = min(SERVICE_UNAVAILABLE_CANCEL_CHECK_SECONDS, remaining_seconds)
            time.sleep(sleep_seconds)
            remaining_seconds -= sleep_seconds

    @staticmethod
    def _ensure_not_canceled(cancellation_checker: CancellationChecker | None) -> None:
        if cancellation_checker is not None and cancellation_checker():
            raise JobCanceledError("Run was canceled by the operator.")

    def process_batch(
        self,
        uploaded_files: list[UploadedImage],
        status_callback: StatusCallback | None = None,
        cancellation_checker: CancellationChecker | None = None,
    ) -> BatchProcessingResult:
        if not uploaded_files:
            return BatchProcessingResult(rows=[], leftover_files=[], usage=self.current_usage_snapshot())

        try:
            self._ensure_not_canceled(cancellation_checker)
        except JobCanceledError:
            rows = [
                self.empty_data(uploaded.file_name, uploaded.sender, uploaded.source_path)
                for uploaded in uploaded_files
            ]
            return BatchProcessingResult(
                rows=rows,
                leftover_files=[row["file"] for row in rows],
                usage=self.current_usage_snapshot(),
                message="Run canceled. Partial results are ready.",
            )

        if len(uploaded_files) > self.remaining_request_budget():
            raise RuntimeError(
                f"Found {len(uploaded_files)} images but only {self.remaining_request_budget()} first-pass requests remain today. "
                "Wait for the next day reset or increase the budget."
            )

        total_files = len(uploaded_files)
        results: list[dict[str, str]] = []
        high_demand_skipped_indices: set[int] = set()
        stopped_for_high_demand = False
        canceled_with_partial_results = False
        final_message = "Processing complete. Download actions are ready."

        def append_blank_rows(start_index: int) -> None:
            for remaining in uploaded_files[start_index:]:
                results.append(
                    self.empty_data(remaining.file_name, remaining.sender, remaining.source_path)
                )

        self._emit_status(status_callback, 10, f"Starting extraction for {total_files} images.")

        for index, uploaded in enumerate(uploaded_files):
            try:
                self._ensure_not_canceled(cancellation_checker)
                self._emit_status(
                    status_callback,
                    10 + int((index / max(total_files, 1)) * 70),
                    f"Processing image {index + 1} of {total_files}: {uploaded.file_name}",
                )
                data = self.process_file_with_backoff(
                    uploaded,
                    INITIAL_ATTEMPTS,
                    status_callback=status_callback,
                    progress_hint=10 + int((index / max(total_files, 1)) * 70),
                    cancellation_checker=cancellation_checker,
                    cooldown_minutes=(
                        LATE_SERVICE_UNAVAILABLE_COOLDOWN_MINUTES
                        if len(high_demand_skipped_indices) >= MAX_SERVICE_UNAVAILABLE_SKIPS_BEFORE_STOP
                        else SERVICE_UNAVAILABLE_COOLDOWN_MINUTES
                    ),
                )
                results.append(data)
            except JobCanceledError:
                canceled_with_partial_results = True
                final_message = "Run canceled. Partial results are ready."
                results.append(self.empty_data(uploaded.file_name, uploaded.sender, uploaded.source_path))
                append_blank_rows(index + 1)
                self._emit_status(status_callback, 94, final_message)
                break
            except ServiceUnavailableAfterCooldownError:
                results.append(self.empty_data(uploaded.file_name, uploaded.sender, uploaded.source_path))
                high_demand_skipped_indices.add(index)

                if len(high_demand_skipped_indices) > MAX_SERVICE_UNAVAILABLE_SKIPS_BEFORE_STOP:
                    stopped_for_high_demand = True
                    final_message = (
                        "Gemini is under very high demand today. Partial results are ready; "
                        "try the remaining files again after some time."
                    )
                    self._emit_status(status_callback, 94, final_message)

                    append_blank_rows(index + 1)
                    break

                self._emit_status(
                    status_callback,
                    10 + int(((index + 1) / max(total_files, 1)) * 70),
                    (
                        f"Skipping {uploaded.file_name} because Gemini stayed busy. "
                        f"Waiting {SERVICE_UNAVAILABLE_SKIP_DELAY_MINUTES} minutes before the next image."
                    ),
                )
                try:
                    self._sleep_with_cancel_checks(
                        SERVICE_UNAVAILABLE_SKIP_DELAY_MINUTES * 60,
                        cancellation_checker,
                    )
                except JobCanceledError:
                    canceled_with_partial_results = True
                    final_message = "Run canceled. Partial results are ready."
                    append_blank_rows(index + 1)
                    self._emit_status(status_callback, 94, final_message)
                    break
            except Exception:
                results.append(self.empty_data(uploaded.file_name, uploaded.sender, uploaded.source_path))

        leftover_indices = [index for index, item in enumerate(results) if self.has_missing_fields(item)]
        remaining_retry_budget = self.remaining_request_budget()

        if (
            leftover_indices
            and remaining_retry_budget > 0
            and not stopped_for_high_demand
            and not canceled_with_partial_results
        ):
            priority_order = sorted(
                [item_index for item_index in leftover_indices if item_index not in high_demand_skipped_indices],
                key=lambda item_index: self.retry_priority(results[item_index]),
                reverse=True,
            )

            for retry_position, item_index in enumerate(priority_order[:remaining_retry_budget], start=1):
                try:
                    self._ensure_not_canceled(cancellation_checker)
                except JobCanceledError:
                    canceled_with_partial_results = True
                    final_message = "Run canceled. Partial results are ready."
                    self._emit_status(status_callback, 94, final_message)
                    break

                uploaded = uploaded_files[item_index]
                self._emit_status(
                    status_callback,
                    82 + int((retry_position / max(1, min(len(priority_order), remaining_retry_budget))) * 12),
                    f"Retrying leftover fields for {uploaded.file_name}",
                )
                try:
                    results[item_index] = self.process_file_with_backoff(
                        uploaded,
                        FINAL_RETRY_ATTEMPTS,
                        continue_for_missing=True,
                        current_data=results[item_index],
                        status_callback=status_callback,
                        progress_hint=82 + int((retry_position / max(1, min(len(priority_order), remaining_retry_budget))) * 12),
                        cancellation_checker=cancellation_checker,
                    )
                except JobCanceledError:
                    canceled_with_partial_results = True
                    final_message = "Run canceled. Partial results are ready."
                    self._emit_status(status_callback, 94, final_message)
                    break
                except Exception:
                    continue

        try:
            self._ensure_not_canceled(cancellation_checker)
        except JobCanceledError:
            canceled_with_partial_results = True
            final_message = "Run canceled. Partial results are ready."
        self._emit_status(status_callback, 96, "Inferring city values from address and pin.")
        results = [self.add_city_to_result(item) for item in results]
        leftover_files = [item["file"] for item in results if self.has_missing_fields(item)]
        self._emit_status(status_callback, 100, final_message)

        return BatchProcessingResult(
            rows=results,
            leftover_files=leftover_files,
            usage=self.current_usage_snapshot(),
            message=final_message,
        )
