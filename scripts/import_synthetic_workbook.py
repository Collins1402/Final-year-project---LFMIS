from __future__ import annotations

import argparse
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


def build_sql(workbook: openpyxl.Workbook) -> tuple[str, str, dict[str, int]]:
    synthetic_mark = "SYNTHETIC DEMONSTRATION"
    statements: list[str] = ["SET NAMES utf8mb4;", "SET FOREIGN_KEY_CHECKS = 0;"]
    counts: dict[str, int] = {}

    animals = rows_as_dicts(workbook, "animals")
    animal_rows = []
    for row in animals:
        if str(row.get("tag_number") or "").strip() == "":
            continue
        status = str(row.get("status") or "active").lower()
        if status not in {"active", "sold", "deceased", "transferred"}:
            status = "active"
        animal_rows.append((
            int(row["id"]),
            row["tag_number"],
            str(row["species"]).lower(),
            row.get("breed"),
            row.get("name"),
            str(row["sex"]).lower(),
            date_value(row.get("date_of_birth")),
            float(row["weight_kg"]) if row.get("weight_kg") not in (None, "") else None,
            status,
            datetime_value(row.get("status_changed_at")),
            synthetic_mark,
            None,
        ))
    add_insert(statements, "animals", [
        "id", "tag_number", "species", "breed", "name", "sex", "date_of_birth",
        "weight_kg", "status", "status_changed_at", "notes", "created_by",
    ], animal_rows)
    counts["animals"] = len(animal_rows)

    customers = rows_as_dicts(workbook, "customers")
    customer_id_map = unique_customer_map(customers)
    customer_rows = [
        (customer_id_map[int(row["id"])], None, row["name"], str(row["phone"]) if row.get("phone") not in (None, "") else None,
         f"{row['email']}.invalid" if str(row["email"]).endswith(".invalid") else row["email"])
        for row in customers
    ]
    add_insert(statements, "customers", ["id", "user_id", "name", "phone", "email"], customer_rows)
    counts["customers"] = len(customer_rows)

    health_rows = []
    for row in rows_as_dicts(workbook, "health_records"):
        record_type = str(row["record_type"]).lower()
        if record_type not in {"vaccination", "treatment", "deworming", "checkup", "illness", "issue"}:
            record_type = "checkup"
        health_rows.append((
            int(row["id"]) + 700000,
            int(row["animal_id"]),
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

    activity_rows = []
    for row in rows_as_dicts(workbook, "farm_activities"):
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
            int(row["animal_id"]) if row.get("animal_id") not in (None, "") else None,
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
    for row in rows_as_dicts(workbook, "maintenance_records"):
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

    produce_rows = []
    for row in rows_as_dicts(workbook, "farm_produce"):
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
            int(row["animal_id"]) if row.get("animal_id") not in (None, "") else None,
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
    for offset, row in enumerate(rows_as_dicts(workbook, "inventory_items"), start=1):
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

    transaction_rows = []
    for offset, row in enumerate(rows_as_dicts(workbook, "inventory_transactions"), start=1):
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
    for row in rows_as_dicts(workbook, "products"):
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

    workbook_orders = rows_as_dicts(workbook, "orders")
    workbook_items = rows_as_dicts(workbook, "order_items")
    workbook_animals = rows_as_dicts(workbook, "order_animals")
    order_rows = []
    order_item_rows = []
    order_animal_rows = []
    sale_rows = []
    payment_rows = []

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
        if index > len(workbook_orders):
            break
        mapped_order_id = 100000 + index
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

    unique_order_animals = []
    seen = set()
    for row in workbook_animals:
        animal_id = int(row["animal_id"])
        if animal_id not in seen:
            seen.add(animal_id)
            unique_order_animals.append(animal_id)
    for index, animal_id in enumerate(unique_order_animals, start=1):
        if index <= len(workbook_orders):
            order_animal_rows.append((100000 + index, animal_id))

    workbook_sales = rows_as_dicts(workbook, "sales")
    workbook_payments = rows_as_dicts(workbook, "payments")
    for index, row in enumerate(workbook_sales, start=1):
        order_index = index
        if order_index > len(workbook_orders):
            continue
        mapped_order_id = 100000 + order_index
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
        order_index = index
        if order_index > len(workbook_orders):
            continue
        mapped_order_id = 100000 + order_index
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
    for offset, row in enumerate(rows_as_dicts(workbook, "expenses"), start=1):
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
    for row in rows_as_dicts(workbook, "weather_observations"):
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
    for offset, row in enumerate(rows_as_dicts(workbook, "feedback"), start=1):
        category = str(row["category"]).lower()
        if category not in {"feedback", "complaint", "suggestion"}:
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
        "DELETE FROM payments WHERE id >= 250000 AND id < 251000;",
        "DELETE FROM sales WHERE id >= 200000 AND id < 201000;",
        "DELETE FROM order_animals WHERE order_id >= 100000;",
        "DELETE FROM order_items WHERE id >= 100000;",
        "DELETE FROM orders WHERE id >= 100000;",
        "DELETE FROM feedback WHERE id >= 350000;",
        "DELETE FROM expenses WHERE id >= 300000;",
        "DELETE FROM inventory_transactions WHERE id >= 400000;",
        "DELETE FROM inventory_items WHERE id >= 300000;",
        "DELETE FROM products WHERE id >= 200000;",
        "DELETE FROM weather_observations WHERE source = 'synthetic_demo';",
        "DELETE FROM maintenance_records WHERE id >= 600000;",
        "DELETE FROM farm_produce WHERE id >= 500000;",
        "DELETE FROM farm_activities WHERE id >= 800000;",
        "DELETE FROM health_records WHERE id >= 700000;",
        "DELETE FROM customers WHERE id >= 900000;",
        "DELETE FROM animals WHERE tag_number LIKE 'SIM-%';",
        "SET FOREIGN_KEY_CHECKS = 1;",
    ]
    return "\n\n".join(statements) + "\n", "\n".join(reset) + "\n", counts


def main() -> None:
    parser = argparse.ArgumentParser(description="Convert the LFMIS synthetic Excel workbook into safe staging SQL.")
    parser.add_argument("workbook", type=Path)
    parser.add_argument("--output-dir", type=Path, default=Path("seed_data"))
    args = parser.parse_args()
    workbook = openpyxl.load_workbook(args.workbook, read_only=True, data_only=True)
    required = {"roles", "users", "animals", "health_records", "farm_activities", "maintenance_records",
                "farm_produce", "inventory_items", "inventory_transactions", "customers", "products",
                "orders", "order_items", "order_animals", "sales", "payments", "expenses",
                "feedback", "weather_observations"}
    missing = sorted(required - set(workbook.sheetnames))
    if missing:
        raise SystemExit("Workbook is missing required sheets: " + ", ".join(missing))
    sql, reset, counts = build_sql(workbook)
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
