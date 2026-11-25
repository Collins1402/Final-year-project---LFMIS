from __future__ import annotations

import argparse
import math
from datetime import date, datetime
from pathlib import Path
from typing import Iterable, Iterator

import openpyxl


def sql_text(value: object) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, float)):
        return str(value)
    text = str(value).strip()
    return "'" + text.replace("\\", "\\\\").replace("'", "''") + "'"


def sheet_rows(workbook: openpyxl.Workbook, name: str) -> tuple[list[str], Iterator[list[object]]]:
    sheet = workbook[name]
    values = sheet.iter_rows(values_only=True)
    headers = [str(value).strip() if value is not None else "" for value in next(values)]
    return headers, values


def rows_as_dicts(workbook: openpyxl.Workbook, name: str) -> list[dict[str, object]]:
    headers, values = sheet_rows(workbook, name)
    return [dict(zip(headers, row)) for row in values if any(value is not None for value in row)]


def demo_rows(rows: list[dict[str, object]], share: float, newest: bool = False, source_count: int | None = None) -> list[dict[str, object]]:
    count = math.ceil((len(rows) if source_count is None else source_count) * share)
    if count <= 0:
        return []
    return rows[-count:] if newest else rows[:count]


def date_value(value: object) -> str | None:
    if value in (None, ""):
        return None
    if isinstance(value, (datetime, date)):
        return value.strftime("%Y-%m-%d")
    text = str(value).strip()
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00")).strftime("%Y-%m-%d")
    except ValueError:
        return text[:10]


def datetime_value(value: object) -> str | None:
    if value in (None, ""):
        return None
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    if isinstance(value, date):
        return value.strftime("%Y-%m-%d 00:00:00")
    text = str(value).strip()
    if len(text) == 10:
        return text + " 00:00:00"
    return text[:19].replace("T", " ")


def add_insert(statements: list[str], table: str, columns: list[str], rows: Iterable[Iterable[object]]) -> None:
    batch = list(rows)
    for offset in range(0, len(batch), 400):
        chunk = batch[offset:offset + 400]
        statement = f"INSERT INTO {table} ({', '.join(columns)}) VALUES\n"
        statement += ",\n".join(
            "(" + ", ".join(sql_text(value) for value in row) + ")"
            for row in chunk
        ) + ";"
        statements.append(statement)


def unique_customer_map(customers: list[dict[str, object]]) -> dict[int, int]:
    return {int(row["id"]): 900000 + int(row["id"]) for row in customers}


def farm_herd(source_animals: list[dict[str, object]], date_stamp: str) -> tuple[list[tuple[object, ...]], dict[int, int], dict[str, int]]:
    """Build the requested structured herd while retaining a small set of template traits."""
    template_by_species: dict[str, list[dict[str, object]]] = {}
    for row in source_animals:
        template_by_species.setdefault(str(row.get("species") or "").lower(), []).append(row)

    # The app's eight starter DEMO records include three already-sold cattle,
    # two active goats, two active chickens, and one active rabbit. One third
    # of the chicken group is represented separately as rabbits.
    plan = [("cattle", 37, [("active", 24), ("sold", 3), ("transferred", 5), ("deceased", 5)]),
            ("goat", 10, [("active", 10)]),
            ("sheep", 12, [("active", 12)]),
            ("rabbit", 50, [("active", 50)]),
            ("chicken", 99, [("active", 99)])]
    generated: list[tuple[object, ...]] = []
    output_ids: dict[str, list[int]] = {}
    counts: dict[str, int] = {}
    next_id = 800001
    species_codes = {"cattle": "CAT", "goat": "GOA", "sheep": "SHE", "rabbit": "RAB", "chicken": "CHK"}

    for species, total, status_groups in plan:
        templates = template_by_species.get(species) or [{}]
        output_ids[species] = []
        index = 0
        for status, amount in status_groups:
            for _ in range(amount):
                template = templates[index % len(templates)]
                tag = f"SIM-{species_codes[species]}-{index + 1:03d}"
                sex = "female" if species != "cattle" or index % 4 != 0 else "male"
                changed_at = f"{date_stamp} 12:00:00" if status != "active" else None
                generated.append((
                    next_id, tag, species, template.get("breed") or "Mixed", f"Demo {species.title()} {index + 1:03d}", sex,
                    date_value(template.get("date_of_birth")),
                    float(template["weight_kg"]) if template.get("weight_kg") not in (None, "") else None,
                    status, changed_at, "SYNTHETIC DEMONSTRATION", None,
                ))
                output_ids[species].append(next_id)
                next_id += 1
                index += 1
        counts[species] = total

    animal_id_map: dict[int, int] = {}
    counters = {species: 0 for species in output_ids}
    for row in source_animals:
        species = str(row.get("species") or "").lower()
        choices = output_ids.get(species)
        if choices:
            animal_id_map[int(row["id"])] = choices[counters[species] % len(choices)]
            counters[species] += 1
    return generated, animal_id_map, counts


def build_sql(workbook: openpyxl.Workbook, demo_share: float = 0.30, max_animals: int | None = None) -> tuple[str, str, dict[str, int]]:
    synthetic_mark = "SYNTHETIC DEMONSTRATION"
    statements: list[str] = ["SET NAMES utf8mb4;", "SET FOREIGN_KEY_CHECKS = 0;"]
    counts: dict[str, int] = {}

    all_animals = rows_as_dicts(workbook, "animals")
    if max_animals is not None and max_animals < 208:
        raise ValueError("The structured herd contains 208 generated animal records.")
    animal_rows, animal_id_map, herd_counts = farm_herd(all_animals, date.today().isoformat())
    add_insert(statements, "animals", [
        "id", "tag_number", "species", "breed", "name", "sex", "date_of_birth",
        "weight_kg", "status", "status_changed_at", "notes", "created_by",
    ], animal_rows)
    counts["animals"] = len(animal_rows)
    counts["animals_active"] = sum(status == "active" for row in animal_rows for status in [row[8]])
    counts["cattle_total_including_starter"] = 40
    counts["goats"] = herd_counts["goat"]
    counts["sheep"] = herd_counts["sheep"]
    counts["chickens"] = herd_counts["chicken"]
    counts["rabbits"] = herd_counts["rabbit"]

    customers = demo_rows(rows_as_dicts(workbook, "customers"), demo_share)
    customer_id_map = unique_customer_map(customers)
    customer_rows = [
        (customer_id_map[int(row["id"])], None, f"SYN-{row['name']} [{synthetic_mark}]", str(row["phone"]) if row.get("phone") not in (None, "") else None,
         str(row["email"]).replace("'", "''"))
        for row in customers
    ]
    add_insert(statements, "customers", ["id", "user_id", "name", "phone", "email"], customer_rows)
    counts["customers"] = len(customer_rows)

    all_health = rows_as_dicts(workbook, "health_records")
    eligible_health = [row for row in all_health if int(row["animal_id"]) in animal_id_map]
    health_rows = []
    for row in demo_rows(eligible_health, demo_share, newest=True, source_count=len(all_health)):
        record_type = str(row["record_type"]).lower()
        if record_type not in {"vaccination", "treatment", "deworming", "checkup", "illness", "issue"}:
            record_type = "checkup"
        health_rows.append((
            int(row["id"]) + 700000,
            animal_id_map.get(int(row["animal_id"]), 800000 + int(row["animal_id"])),
            record_type,
            f"{row['description']} [{synthetic_mark}]",
            row.get("medication"),
            row.get("dosage"),
            float(row["cost"]) if row.get("cost") not in (None, "") else 0,
            date_value(row["record_date"]),
            date_value(row.get("next_due_date")),
            None,
            0,
            None,
            None,
        ))
    add_insert(statements, "health_records", [
        "id", "animal_id", "record_type", "description", "medication", "dosage",
        "cost", "record_date", "next_due_date", "reminded_at", "reminder_attempts",
        "reminder_error", "recorded_by",
    ], health_rows)
    counts["health_records"] = len(health_rows)

    all_activities = rows_as_dicts(workbook, "farm_activities")
    eligible_activities = [row for row in all_activities if row.get("animal_id") in (None, "") or int(row["animal_id"]) in animal_id_map]
    activity_rows = []
    for row in demo_rows(eligible_activities, demo_share, newest=True, source_count=len(all_activities)):
        activity_type = str(row["activity_type"]).lower()
        if activity_type not in {"feeding", "milking", "cleaning", "breeding", "health", "maintenance", "other"}:
            activity_type = "other"
        status = str(row["status"]).lower()
        if status not in {"planned", "completed", "cancelled"}:
            status = "planned"
        activity_rows.append((
            int(row["id"]) + 800000,
            activity_type,
            f"{row['description']} [{synthetic_mark}]",
            animal_id_map.get(int(row["animal_id"]), 800000 + int(row["animal_id"])) if row.get("animal_id") not in (None, "") else None,
            None,
            datetime_value(row["activity_at"]),
            status,
            datetime_value(row.get("completed_at")),
            None,
            None,
        ))
    add_insert(statements, "farm_activities", [
        "id", "activity_type", "description", "animal_id", "assigned_to", "activity_at",
        "status", "completed_at", "completed_by", "created_by",
    ], activity_rows)
    counts["farm_activities"] = len(activity_rows)

    maintenance_rows = []
    for row in demo_rows(rows_as_dicts(workbook, "maintenance_records"), demo_share, newest=True):
        asset_type = str(row["asset_type"]).lower()
        if asset_type not in {"equipment", "housing", "water", "vehicle", "other"}:
            asset_type = "other"
        maintenance_rows.append((
            int(row["id"]) + 600000,
            f"SYN-{row['asset_name']}",
            asset_type,
            date_value(row["maintenance_date"]),
            f"{row['description']} [{synthetic_mark}]",
            float(row["cost"]) if row.get("cost") not in (None, "") else 0,
            date_value(row.get("next_due_date")),
            synthetic_mark,
            None,
        ))
    add_insert(statements, "maintenance_records", [
        "id", "asset_name", "asset_type", "maintenance_date", "description", "cost",
        "next_due_date", "notes", "recorded_by",
    ], maintenance_rows)
    counts["maintenance_records"] = len(maintenance_rows)

    all_produce = rows_as_dicts(workbook, "farm_produce")
    eligible_produce = [row for row in all_produce if row.get("animal_id") in (None, "") or int(row["animal_id"]) in animal_id_map]
    produce_rows = []
    for row in demo_rows(eligible_produce, demo_share, newest=True, source_count=len(all_produce)):
        quantity = float(row["quantity_produced"])
        sold = float(row.get("quantity_sold") or 0)
        damaged = float(row.get("quantity_damaged") or 0)
        if sold + damaged > quantity:
            sold = 0
            damaged = 0
        produce_rows.append((
            int(row["id"]) + 500000,
            str(row["produce_name"]),
            date_value(row["production_date"]),
            row.get("production_session"),
            animal_id_map.get(int(row["animal_id"]), 800000 + int(row["animal_id"])) if row.get("animal_id") not in (None, "") else None,
            quantity,
            sold,
            damaged,
            str(row["unit"]).lower(),
            synthetic_mark,
            None,
        ))
    add_insert(statements, "farm_produce", [
        "id", "produce_name", "production_date", "production_session", "animal_id",
        "quantity_produced", "quantity_sold", "quantity_damaged", "unit", "notes", "recorded_by",
    ], produce_rows)
    counts["farm_produce"] = len(produce_rows)

    inventory_id_map: dict[int, int] = {}
    inventory_rows = []
    all_inventory = rows_as_dicts(workbook, "inventory_items")
    for offset, row in enumerate(demo_rows(all_inventory, demo_share), start=1):
        original_id = int(row["id"])
        mapped_id = 300000 + offset
        inventory_id_map[original_id] = mapped_id
        category = str(row["category"]).lower()
        if category not in {"feed", "medicine", "equipment", "supplies", "other"}:
            category = "other"
        inventory_rows.append((
            mapped_id,
            f"SYN-{row['name']}",
            category,
            str(row["unit"]).lower(),
            float(row["quantity_on_hand"]),
            float(row["reorder_level"]),
            float(row["unit_cost"]) if row.get("unit_cost") not in (None, "") else None,
            1,
        ))
    add_insert(statements, "inventory_items", [
        "id", "name", "category", "unit", "quantity_on_hand", "reorder_level", "unit_cost", "is_active",
    ], inventory_rows)
    counts["inventory_items"] = len(inventory_rows)

    all_transactions = rows_as_dicts(workbook, "inventory_transactions")
    eligible_transactions = [row for row in all_transactions if int(row["item_id"]) in inventory_id_map]
    transaction_rows = []
    for offset, row in enumerate(demo_rows(eligible_transactions, demo_share, newest=True, source_count=len(all_transactions)), start=1):
        transaction_type = str(row["transaction_type"]).lower()
        if transaction_type not in {"in", "out", "adjustment"}:
            transaction_type = "adjustment"
        transaction_rows.append((
            400000 + offset,
            inventory_id_map[int(row["item_id"])],
            transaction_type,
            float(row["quantity_change"]),
            float(row["unit_cost"]) if row.get("unit_cost") not in (None, "") else None,
            f"{row['reason']} [{synthetic_mark}]",
            datetime_value(row["transaction_date"]),
            None,
        ))
    add_insert(statements, "inventory_transactions", [
        "id", "item_id", "transaction_type", "quantity_change", "unit_cost", "reason", "transaction_date", "recorded_by",
    ], transaction_rows)
    counts["inventory_transactions"] = len(transaction_rows)

    product_rows = []
    for row in demo_rows(rows_as_dicts(workbook, "products"), demo_share):
        name = str(row["name"])
        product_type = str(row["product_type"]).lower()
        if product_type not in {"livestock", "produce", "other"}:
            product_type = "other"
        production_kind = None
        if product_type == "produce":
            if "milk" in name.lower():
                production_kind = "milk"
            elif "egg" in name.lower():
                production_kind = "eggs"
            elif "wool" in name.lower():
                production_kind = "wool"
        species = str(row["species"]).lower() if row.get("species") not in (None, "") else None
        if species not in {"cattle", "goat", "rabbit", "chicken", "sheep", "other"}:
            species = None
        product_rows.append((
            int(row["id"]) + 200000,
            f"SYN-{name}",
            product_type,
            production_kind,
            species,
            str(row["unit"]).lower(),
            float(row["available_quantity"]),
            float(row["unit_price"]),
            1,
        ))
    add_insert(statements, "products", [
        "id", "name", "product_type", "production_kind", "species", "unit",
        "available_quantity", "unit_price", "is_active",
    ], product_rows)
    counts["products"] = len(product_rows)

    all_orders = rows_as_dicts(workbook, "orders")
    eligible_orders = [row for row in all_orders if int(row["customer_id"]) in customer_id_map]
    workbook_orders = demo_rows(eligible_orders, demo_share, newest=True, source_count=len(all_orders))
    all_items = rows_as_dicts(workbook, "order_items")
    workbook_items = all_items
    all_order_animals = rows_as_dicts(workbook, "order_animals")
    workbook_animals = [row for row in all_order_animals if int(row["animal_id"]) in animal_id_map]
    order_rows = []
    order_item_rows = []
    order_animal_rows = []
    sale_rows = []
    payment_rows = []
    order_id_map = {int(row["id"]): 100000 + index for index, row in enumerate(workbook_orders, start=1)}
    workbook_items = demo_rows([row for row in all_items if int(row.get("order_id") or -1) in order_id_map], demo_share, newest=True, source_count=len(all_items))
    workbook_animals = demo_rows([row for row in workbook_animals if int(row.get("order_id") or -1) in order_id_map], demo_share, newest=True, source_count=len(all_order_animals))

    for index, row in enumerate(workbook_orders, start=1):
        mapped_order_id = 100000 + index
        original_customer_id = int(row["customer_id"])
        customer_id = customer_id_map.get(original_customer_id)
        status = str(row["status"]).lower()
        if status not in {"pending", "quoted", "accepted", "paid", "fulfilled", "cancelled"}:
            status = "pending"
        total = float(row["total_amount"])
        handled_by = None
        ordered_at = datetime_value(row["ordered_at"])
        accepted_at = datetime_value(row.get("accepted_at"))
        fulfilled_at = datetime_value(row.get("fulfilled_at"))
        order_rows.append((
            mapped_order_id,
            customer_id,
            status,
            total,
            f"SYNTHETIC DEMONSTRATION ORDER #{index}",
            handled_by,
            ordered_at,
            accepted_at,
            fulfilled_at,
        ))

    for index, row in enumerate(workbook_items, start=1):
        original_order_id = int(row.get("order_id") or (workbook_orders[index - 1]["id"] if index <= len(workbook_orders) else -1))
        mapped_order_id = order_id_map.get(original_order_id)
        if mapped_order_id is None:
            continue
        item_name = f"SYN-{row['item_name']}"
        order_item_rows.append((
            100000 + index,
            mapped_order_id,
            None,
            item_name,
            float(row["quantity"]),
            str(row["unit"]).lower(),
            float(row["unit_price"]),
        ))

    linked_animals = set()
    for row in workbook_animals:
        animal_id = int(row["animal_id"])
        original_order_id = int(row.get("order_id") or -1)
        mapped_order_id = order_id_map.get(original_order_id)
        if mapped_order_id is not None and animal_id not in linked_animals:
            order_animal_rows.append((mapped_order_id, animal_id_map[animal_id]))
            linked_animals.add(animal_id)

    all_sales = rows_as_dicts(workbook, "sales")
    workbook_sales = demo_rows([row for row in all_sales if int(row.get("order_id") or -1) in order_id_map], demo_share, newest=True, source_count=len(all_sales))
    all_payments = rows_as_dicts(workbook, "payments")
    workbook_payments = demo_rows([row for row in all_payments if int(row.get("order_id") or -1) in order_id_map], demo_share, newest=True, source_count=len(all_payments))
    for index, row in enumerate(workbook_sales, start=1):
        original_order_id = int(row.get("order_id") or (workbook_orders[index - 1]["id"] if index <= len(workbook_orders) else -1))
        mapped_order_id = order_id_map.get(original_order_id)
        if mapped_order_id is None:
            continue
        customer_id = customer_id_map.get(int(row["customer_id"]))
        sale_rows.append((
            200000 + index,
            mapped_order_id,
            customer_id,
            f"{row['description']} [{synthetic_mark}]",
            float(row["quantity"]),
            str(row["unit"]).lower(),
            float(row["unit_price"]),
            datetime_value(row["sold_at"]),
            None,
        ))
    for index, row in enumerate(workbook_payments, start=1):
        original_order_id = int(row.get("order_id") or (workbook_orders[index - 1]["id"] if index <= len(workbook_orders) else -1))
        mapped_order_id = order_id_map.get(original_order_id)
        if mapped_order_id is None:
            continue
        customer_id = customer_id_map.get(int(row["customer_id"]))
        status = str(row["status"]).lower()
        if status not in {"pending", "confirmed", "failed", "refunded"}:
            status = "pending"
        reference = None if status == "pending" else f"SYN-PAY-{index:06d}"
        payment_rows.append((
            250000 + index,
            mapped_order_id,
            customer_id,
            float(row["amount"]),
            str(row["method"]).lower(),
            status,
            reference,
            None,
            None,
            str(row["phone"]) if row.get("phone") not in (None, "") else None,
            f"Synthetic payment [{synthetic_mark}]",
            datetime_value(row.get("paid_at")),
            None,
        ))

    add_insert(statements, "orders", [
        "id", "customer_id", "status", "total_amount", "notes", "handled_by",
        "ordered_at", "accepted_at", "fulfilled_at",
    ], order_rows)
    add_insert(statements, "order_items", [
        "id", "order_id", "product_id", "item_name", "quantity", "unit", "unit_price",
    ], order_item_rows)
    add_insert(statements, "order_animals", ["order_id", "animal_id"], order_animal_rows)
    add_insert(statements, "sales", [
        "id", "order_id", "customer_id", "description", "quantity", "unit", "unit_price", "sold_at", "recorded_by",
    ], sale_rows)
    add_insert(statements, "payments", [
        "id", "order_id", "customer_id", "amount", "method", "status", "transaction_reference",
        "checkout_request_id", "merchant_request_id", "phone", "result_desc", "paid_at", "recorded_by",
    ], payment_rows)
    counts["orders"] = len(order_rows)
    counts["order_items"] = len(order_item_rows)
    counts["order_animals"] = len(order_animal_rows)
    counts["sales"] = len(sale_rows)
    counts["payments"] = len(payment_rows)

    expense_rows = []
    for offset, row in enumerate(demo_rows(rows_as_dicts(workbook, "expenses"), demo_share, newest=True), start=1):
        category = str(row["category"]).lower()
        if category not in {"feed", "veterinary", "labour", "equipment", "transport", "utilities", "maintenance", "other"}:
            category = "other"
        expense_rows.append((
            300000 + offset,
            category,
            f"{row['description']} [{synthetic_mark}]",
            float(row["amount"]),
            date_value(row["expense_date"]),
            None,
        ))
    add_insert(statements, "expenses", [
        "id", "category", "description", "amount", "expense_date", "recorded_by",
    ], expense_rows)
    counts["expenses"] = len(expense_rows)

    weather_rows = []
    for row in demo_rows(rows_as_dicts(workbook, "weather_observations"), demo_share, newest=True):
        weather_rows.append((
            date_value(row["observation_date"]),
            float(row["rain_mm"]) if row.get("rain_mm") not in (None, "") else None,
            float(row["temp_c"]) if row.get("temp_c") not in (None, "") else None,
            "synthetic_demo",
        ))
    add_insert(statements, "weather_observations", [
        "observation_date", "rain_mm", "temp_c", "source",
    ], weather_rows)
    counts["weather_observations"] = len(weather_rows)

    feedback_rows = []
    for offset, row in enumerate(demo_rows(rows_as_dicts(workbook, "feedback"), demo_share, newest=True), start=1):
        category = str(row["category"]).lower()
        if category not in {"feedback", "complaint", "suggestion", "compliment"}:
            category = "feedback"
        status = str(row["status"]).lower()
        if status not in {"open", "resolved"}:
            status = "open"
        feedback_rows.append((
            350000 + offset,
            None,
            category,
            f"{row['message']} [{synthetic_mark}]",
            status,
            None,
            datetime_value(row.get("resolved_at")),
            datetime_value(row["created_at"]),
        ))
    add_insert(statements, "feedback", [
        "id", "user_id", "category", "message", "status", "resolved_by", "resolved_at", "created_at",
    ], feedback_rows)
    counts["feedback"] = len(feedback_rows)

    statements.append("SET FOREIGN_KEY_CHECKS = 1;")

    reset = [
        "SET FOREIGN_KEY_CHECKS = 0;",
        "DELETE FROM payments WHERE result_desc LIKE '%[SYNTHETIC DEMONSTRATION]%' OR order_id IN (SELECT id FROM orders WHERE notes LIKE 'SYNTHETIC DEMONSTRATION ORDER%');",
        "DELETE FROM sales WHERE description LIKE '%[SYNTHETIC DEMONSTRATION]%' OR order_id IN (SELECT id FROM orders WHERE notes LIKE 'SYNTHETIC DEMONSTRATION ORDER%');",
        "DELETE FROM order_animals WHERE order_id IN (SELECT id FROM orders WHERE notes LIKE 'SYNTHETIC DEMONSTRATION ORDER%');",
        "DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE notes LIKE 'SYNTHETIC DEMONSTRATION ORDER%') OR item_name LIKE 'SYN-%';",
        "DELETE FROM orders WHERE notes LIKE 'SYNTHETIC DEMONSTRATION ORDER%';",
        "DELETE FROM feedback WHERE message LIKE '%[SYNTHETIC DEMONSTRATION]%';",
        "DELETE FROM expenses WHERE description LIKE '%[SYNTHETIC DEMONSTRATION]%';",
        "DELETE FROM inventory_transactions WHERE reason LIKE '%[SYNTHETIC DEMONSTRATION]%';",
        "DELETE FROM inventory_items WHERE name LIKE 'SYN-%';",
        "DELETE FROM products WHERE name LIKE 'SYN-%';",
        "DELETE FROM weather_observations WHERE source = 'synthetic_demo';",
        "DELETE FROM maintenance_records WHERE notes = 'SYNTHETIC DEMONSTRATION';",
        "DELETE FROM farm_produce WHERE notes = 'SYNTHETIC DEMONSTRATION';",
        "DELETE FROM farm_activities WHERE description LIKE '%[SYNTHETIC DEMONSTRATION]%';",
        "DELETE FROM health_records WHERE description LIKE '%[SYNTHETIC DEMONSTRATION]%';",
        "DELETE FROM customers WHERE name LIKE '%[SYNTHETIC DEMONSTRATION]%' OR email LIKE '%@sim.flouri.invalid';",
        "DELETE FROM animals WHERE notes = 'SYNTHETIC DEMONSTRATION' OR tag_number LIKE 'SIM-%';",
        "SET FOREIGN_KEY_CHECKS = 1;",
    ]
    return "\n\n".join(statements) + "\n", "\n".join(reset) + "\n", counts


def main() -> None:
    parser = argparse.ArgumentParser(description="Convert the LFMIS synthetic Excel workbook into safe staging SQL.")
    parser.add_argument("workbook", type=Path)
    parser.add_argument("--output-dir", type=Path, default=Path("seed_data"))
    parser.add_argument("--demo-share", type=float, default=0.30, help="Share of each workbook sheet to retain (default: 0.30).")
    parser.add_argument("--max-animals", type=int, help="Compatibility check only; the structured herd always contains 208 generated records.")
    args = parser.parse_args()
    if not 0 < args.demo_share <= 1:
        parser.error("--demo-share must be greater than 0 and at most 1.")
    if args.max_animals is not None and args.max_animals < 1:
        parser.error("--max-animals must be at least 1.")
    workbook = openpyxl.load_workbook(args.workbook, read_only=True, data_only=True)
    required = {"roles", "users", "animals", "health_records", "farm_activities", "maintenance_records",
                "farm_produce", "inventory_items", "inventory_transactions", "customers", "products",
                "orders", "order_items", "order_animals", "sales", "payments", "expenses",
                "feedback", "weather_observations"}
    missing = sorted(required - set(workbook.sheetnames))
    if missing:
        raise SystemExit("Workbook is missing required sheets: " + ", ".join(missing))
    sql, reset, counts = build_sql(workbook, args.demo_share, args.max_animals)
    args.output_dir.mkdir(parents=True, exist_ok=True)
    import_path = args.output_dir / "synthetic-workbook.sql"
    reset_path = args.output_dir / "reset-synthetic-workbook.sql"
    import_path.write_text(sql, encoding="utf-8")
    reset_path.write_text(reset, encoding="utf-8")
    print(f"Wrote {import_path}")
    print(f"Wrote {reset_path}")
    for name, count in counts.items():
        print(f"{name}: {count}")


if __name__ == "__main__":
    main()
