from __future__ import annotations

import csv
import io
from typing import Iterable


CSV_COLUMNS = ["date", "file", "sender", "receiver", "address", "city", "pin", "phone", "awb"]


def csv_export_value(column: str, value: object) -> str:
    text = str(value or "")
    if column == "awb" and text.isdigit() and len(text) >= 10:
        return f'="{text}"'
    return text


def rows_to_csv_bytes(rows: Iterable[dict]) -> bytes:
    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=CSV_COLUMNS, extrasaction="ignore")
    writer.writeheader()

    for row in rows:
        writer.writerow({column: csv_export_value(column, row.get(column, "")) for column in CSV_COLUMNS})

    return ("\ufeff" + output.getvalue()).encode("utf-8")
