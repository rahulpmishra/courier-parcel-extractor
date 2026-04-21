from google import genai
from PIL import Image
from datetime import date
import difflib, json, re, os, pandas as pd, time, sys

# CONFIG
MODEL = "models/gemini-3.1-flash-lite-preview"
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
API_KEY_PATH = os.path.join(BASE_DIR, "api.txt")
USAGE_FILE_PATH = os.path.join(BASE_DIR, "api_usage.json")
IMAGE_FOLDER = (
    sys.argv[1]
    if len(sys.argv) > 1
    else os.environ.get("IMAGE_FOLDER", os.path.join(BASE_DIR, "sample_images"))
)
CITIES_MAP_PATH = os.path.join(BASE_DIR, "cities", "final_pincode_map.json")
SENDER_NAME = os.path.basename(os.path.normpath(IMAGE_FOLDER))
REQUESTS_PER_MINUTE = 13
DEFAULT_DAILY_REQUEST_BUDGET = 300
INITIAL_ATTEMPTS = 1
FINAL_RETRY_ATTEMPTS = 1
SERVICE_UNAVAILABLE_THRESHOLD = 2
SERVICE_UNAVAILABLE_COOLDOWN_MINUTES = 10
MAX_SERVICE_UNAVAILABLE_COOLDOWNS_PER_FILE = 3
RUN_DATE = date.today().isoformat()

# PROMPT
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


def load_api_key():
    if not os.path.exists(API_KEY_PATH):
        raise FileNotFoundError(
            f"API key file not found: {API_KEY_PATH}. Paste your API key into api.txt."
        )

    with open(API_KEY_PATH, "r", encoding="utf-8") as f:
        for line in f:
            candidate = line.strip()

            if not candidate or candidate.startswith("#"):
                continue

            if "=" in candidate:
                candidate = candidate.split("=", 1)[1].strip()

            candidate = candidate.strip().strip('"').strip("'")
            if candidate:
                return candidate

    raise ValueError(f"No API key found in {API_KEY_PATH}.")


def normalize_daily_budget(value):
    try:
        budget = int(value)
    except:
        budget = DEFAULT_DAILY_REQUEST_BUDGET

    return budget if budget > 0 else DEFAULT_DAILY_REQUEST_BUDGET


def usage_snapshot(requests_used_value, daily_budget_value):
    daily_budget = normalize_daily_budget(daily_budget_value)

    try:
        used = int(requests_used_value)
    except:
        used = 0

    used = max(0, min(used, daily_budget))

    return {
        "date": RUN_DATE,
        "daily_budget": daily_budget,
        "requests_used": used,
        "requests_remaining": max(daily_budget - used, 0)
    }


def save_usage_file(requests_used_value, daily_budget_value):
    snapshot = usage_snapshot(requests_used_value, daily_budget_value)

    with open(USAGE_FILE_PATH, "w", encoding="utf-8") as f:
        json.dump(snapshot, f, indent=2)

    return snapshot


def load_usage_file():
    if not os.path.exists(USAGE_FILE_PATH):
        return save_usage_file(0, DEFAULT_DAILY_REQUEST_BUDGET)

    try:
        with open(USAGE_FILE_PATH, "r", encoding="utf-8") as f:
            data = json.load(f)
    except:
        return save_usage_file(0, DEFAULT_DAILY_REQUEST_BUDGET)

    if not isinstance(data, dict):
        return save_usage_file(0, DEFAULT_DAILY_REQUEST_BUDGET)

    daily_budget = normalize_daily_budget(data.get("daily_budget", DEFAULT_DAILY_REQUEST_BUDGET))

    if str(data.get("date", "")).strip() != RUN_DATE:
        return save_usage_file(0, daily_budget)

    return save_usage_file(data.get("requests_used", 0), daily_budget)


# INIT CLIENT
client = genai.Client(api_key=load_api_key())
EXTRACTED_FIELDS = ["receiver", "address", "pin", "phone", "awb"]
OUTPUT_FIELDS = ["receiver", "address", "city", "pin", "phone", "awb"]
REQUEST_INTERVAL = 60 / REQUESTS_PER_MINUTE
last_request_time = 0.0
usage_state = load_usage_file()
daily_request_budget = usage_state["daily_budget"]
requests_used = usage_state["requests_used"]


def empty_data(file_name=""):
    return {
        "date": RUN_DATE,
        "file": file_name,
        "sender": SENDER_NAME,
        "receiver": "",
        "address": "",
        "city": "",
        "pin": "",
        "phone": "",
        "awb": ""
    }


def normalize_value(value):
    if value is None:
        return ""

    if isinstance(value, list):
        return " ".join(normalize_value(item) for item in value if item is not None).strip()

    if isinstance(value, dict):
        return json.dumps(value, ensure_ascii=False)

    return str(value).strip()


def remaining_request_budget():
    return max(daily_request_budget - requests_used, 0)


def record_request_hit():
    global requests_used

    requests_used += 1
    save_usage_file(requests_used, daily_request_budget)


def should_count_request_error(error):
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


def throttle_request():
    global last_request_time

    if last_request_time:
        elapsed = time.time() - last_request_time
        if elapsed < REQUEST_INTERVAL:
            time.sleep(REQUEST_INTERVAL - elapsed)

    last_request_time = time.time()


def supported_image_files():
    files = []
    for file in sorted(os.listdir(IMAGE_FOLDER)):
        if file.lower().endswith((".jpg", ".jpeg", ".png")):
            files.append(file)
    return files


def is_service_unavailable_error(error):
    text = str(error)
    text_upper = text.upper()
    return (
        "503" in text_upper
        and "UNAVAILABLE" in text_upper
    ) or "HIGH DEMAND" in text_upper


def countdown_pause(minutes):
    total_seconds = minutes * 60
    print(f"\nServer limit exceeded. Will resume after {minutes} minutes.")

    remaining = total_seconds
    while remaining > 0:
        minutes_left = remaining // 60
        seconds_left = remaining % 60
        print(f"Resuming after {minutes_left:02d}:{seconds_left:02d}")
        sleep_chunk = 60 if remaining >= 60 else remaining
        time.sleep(sleep_chunk)
        remaining -= sleep_chunk

    print("Resuming now...\n")


def extract_json_block(text):
    try:
        return json.loads(text)
    except:
        match = re.search(r"\{.*\}", text, re.S)
        if match:
            return json.loads(match.group(0))
        raise


def pick_pin(value):
    text = normalize_value(value)
    match = re.search(r"(?<!\d)(\d{6})(?!\d)", text)
    return match.group(1) if match else ""


def pick_phone(value):
    text = normalize_value(value)
    matches = re.findall(r"(?<!\d)(?:91[\s\-]*)?([6-9]\d{9})(?!\d)", text)
    return matches[0] if matches else ""


def pick_awb(value):
    text = normalize_value(value)
    match = re.search(r"(?<!\d)(\d{12})(?!\d)", text)
    return match.group(1) if match else ""


def load_city_map():
    if not os.path.exists(CITIES_MAP_PATH):
        return {}

    with open(CITIES_MAP_PATH, "r", encoding="utf-8") as f:
        raw_data = json.load(f)

    if not isinstance(raw_data, dict):
        return {}

    city_map = {}

    for raw_pin, raw_names in raw_data.items():
        pin = pick_pin(raw_pin)
        if not pin or not isinstance(raw_names, list):
            continue

        cleaned_names = []
        seen = set()

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


def normalize_match_text(value):
    text = normalize_value(value).lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def build_address_phrases(address, max_words):
    normalized_address = normalize_match_text(address)
    tokens = normalized_address.split()

    if not tokens:
        return []

    phrases = {}

    for start in range(len(tokens)):
        max_length = min(max_words, len(tokens) - start)

        for length in range(1, max_length + 1):
            phrase = " ".join(tokens[start:start + length])
            end_position = start + length

            if phrase not in phrases or end_position > phrases[phrase]:
                phrases[phrase] = end_position

    return list(phrases.items())


def fuzzy_threshold(candidate_text):
    compact = candidate_text.replace(" ", "")
    length = len(compact)

    if length <= 4:
        return 0.97

    if length <= 6:
        return 0.94

    if length <= 8:
        return 0.91

    return 0.88


def find_best_location_match(address, candidates):
    normalized_candidates = []
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

    best_exact = None
    for index, candidate, candidate_norm in normalized_candidates:
        if candidate_norm not in phrase_positions:
            continue

        score = (
            phrase_positions[candidate_norm],
            len(candidate_norm.replace(" ", "")),
            -index
        )

        if best_exact is None or score > best_exact[0]:
            best_exact = (score, candidate)

    if best_exact:
        return best_exact[1]

    best_fuzzy = None
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
                -index
            )

            if best_fuzzy is None or score > best_fuzzy[0]:
                best_fuzzy = (score, candidate)

    return best_fuzzy[1] if best_fuzzy else ""


PINCODE_CITY_MAP = load_city_map()


def infer_city(address, pin):
    pin_code = pick_pin(pin)
    if not pin_code:
        return ""

    address_text = normalize_value(address)
    if not address_text:
        return ""

    location_list = PINCODE_CITY_MAP.get(pin_code, [])
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


# VALIDATION FUNCTION
def validate_json(text):
    try:
        data = extract_json_block(text)

        if not isinstance(data, dict):
            return empty_data()

        validated = {
            "sender": SENDER_NAME,
            "receiver": normalize_value(data.get("receiver", "") or data.get("name", "")),
            "address": normalize_value(data.get("address", "")),
            "city": "",
            "pin": normalize_value(data.get("pin", "")),
            "phone": normalize_value(data.get("phone", "")),
            "awb": normalize_value(data.get("awb", "")),
        }

        # strict validation with first usable value kept
        validated["pin"] = pick_pin(validated["pin"])
        validated["phone"] = pick_phone(validated["phone"])
        validated["awb"] = pick_awb(validated["awb"])

        return validated

    except:
        return {
            "sender": SENDER_NAME,
            "receiver": "",
            "address": "",
            "city": "",
            "pin": pick_pin(text),
            "phone": pick_phone(text),
            "awb": pick_awb(text)
        }


def merge_data(current_data, new_data):
    merged = {
        "date": current_data.get("date") or new_data.get("date", "") or RUN_DATE,
        "file": current_data.get("file") or new_data.get("file", ""),
        "sender": current_data.get("sender") or new_data.get("sender", "") or SENDER_NAME,
        "receiver": "",
        "address": "",
        "city": current_data.get("city") or new_data.get("city", "")
    }

    for key in EXTRACTED_FIELDS:
        merged[key] = current_data.get(key, "") or new_data.get(key, "")

    return merged


def has_missing_fields(data):
    return any(not data.get(key, "") for key in EXTRACTED_FIELDS)


def run_request(image):
    if remaining_request_budget() <= 0:
        raise RuntimeError(
            f"Daily request budget reached ({daily_request_budget}). "
            "Stopping further Gemini calls for today."
        )

    throttle_request()
    try:
        response = client.models.generate_content(
            model=MODEL,
            contents=[PROMPT, image]
        )
    except Exception as e:
        if should_count_request_error(e):
            record_request_hit()
        raise

    record_request_hit()
    return validate_json((response.text or "").strip())


def process_image(path, file_name, attempts, continue_for_missing=False, current_data=None):
    best_data = current_data.copy() if current_data else empty_data(file_name)
    image = Image.open(path)
    image.thumbnail((1024, 1024))  # optimize size

    for attempt in range(attempts):
        new_data = run_request(image)
        new_data["file"] = file_name
        best_data = merge_data(best_data, new_data)

        if continue_for_missing:
            if not has_missing_fields(best_data):
                break

            print(f"Retrying leftover fields for {file_name}...")
        else:
            # success condition
            if best_data["address"] or best_data["awb"]:
                break

            print(f"Retrying {file_name}...")

    return best_data


def process_file_with_backoff(path, file_name, attempts, continue_for_missing=False, current_data=None):
    consecutive_service_unavailable = 0
    cooldowns_used = 0

    while True:
        try:
            return process_image(
                path,
                file_name,
                attempts,
                continue_for_missing=continue_for_missing,
                current_data=current_data
            )
        except Exception as e:
            if not is_service_unavailable_error(e):
                raise

            consecutive_service_unavailable += 1
            print(
                f"Server busy for {file_name} "
                f"({consecutive_service_unavailable}/{SERVICE_UNAVAILABLE_THRESHOLD}) -> {e}"
            )

            if consecutive_service_unavailable >= SERVICE_UNAVAILABLE_THRESHOLD:
                cooldowns_used += 1
                countdown_pause(SERVICE_UNAVAILABLE_COOLDOWN_MINUTES)
                consecutive_service_unavailable = 0

                if cooldowns_used >= MAX_SERVICE_UNAVAILABLE_COOLDOWNS_PER_FILE:
                    raise RuntimeError(
                        f"Server kept returning 503 for {file_name} even after cooldown retries."
                    )

            else:
                print("Will retry this file again...")
                time.sleep(REQUEST_INTERVAL)


def retry_priority(item):
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

    score += filled_count * 2
    return score


def add_city_to_result(item):
    enriched = item.copy()
    enriched["city"] = infer_city(enriched.get("address", ""), enriched.get("pin", ""))
    return enriched


# STORE RESULTS
results = []
image_paths = {}
image_files = supported_image_files()

print(
    f"Saved API usage for {RUN_DATE}: "
    f"{requests_used}/{daily_request_budget} used, {remaining_request_budget()} remaining."
)

if len(image_files) > remaining_request_budget():
    raise RuntimeError(
        f"Found {len(image_files)} images but only {remaining_request_budget()} first-pass requests remain today. "
        "Wait for the next day reset or increase the budget."
    )

# PROCESS IMAGES
for file in image_files:
    path = os.path.join(IMAGE_FOLDER, file)
    image_paths[file] = path

    print(f"Processing: {file}")

    try:
        data = process_file_with_backoff(path, file, INITIAL_ATTEMPTS)
        results.append(data)

    except Exception as e:
        print(f"Error: {file} -> {e}")
        results.append(empty_data(file))


# FINAL RETRY FOR LEFTOVER FILES
leftover_items = [item for item in results if has_missing_fields(item)]
leftover_items.sort(key=retry_priority, reverse=True)
remaining_retry_budget = remaining_request_budget()

if leftover_items and remaining_retry_budget > 0:
    print("\nFinal retry pass for leftover files:")
    print(f"Remaining retry budget today: {remaining_retry_budget}")

    for item in leftover_items[:remaining_retry_budget]:
        print(item["file"])

    for item in leftover_items[:remaining_retry_budget]:
        index = next(i for i, row in enumerate(results) if row["file"] == item["file"])

        try:
            results[index] = process_file_with_backoff(
                image_paths[item["file"]],
                item["file"],
                FINAL_RETRY_ATTEMPTS,
                continue_for_missing=True,
                current_data=item
            )
        except Exception as e:
            print(f"Error in final retry: {item['file']} -> {e}")

elif leftover_items:
    print("\nNo retry budget left for leftover files today.")


# ADD CITY COLUMN USING ADDRESS + PINCODE MAP
results = [add_city_to_result(item) for item in results]


# SAVE COMBINED JSON
with open("all_results.json", "w", encoding="utf-8") as f:
    json.dump(results, f, indent=2)


# SAVE CSV
column_order = ["date", "file", "sender"] + OUTPUT_FIELDS
df = pd.DataFrame(results, columns=column_order)
df.to_csv("output.csv", index=False, encoding="utf-8-sig")


leftover_files = [item["file"] for item in results if has_missing_fields(item)]

print("\nDONE!")
print("CSV: output.csv")
print("Combined JSON: all_results.json")
print(f"Requests used today: {requests_used}/{daily_request_budget}")
print(f"Requests remaining today: {remaining_request_budget()}/{daily_request_budget}")

if leftover_files:
    print("\nStill left after all retries:")
    for file in leftover_files:
        print(file)
else:
    print("\nNo file left with blank details.")
