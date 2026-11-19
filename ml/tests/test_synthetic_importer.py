from pathlib import Path

import openpyxl

from scripts.import_synthetic_workbook import build_sql, unique_customer_map


def test_workbook_converter_remaps_orders_and_labels_data(tmp_path):
    workbook = openpyxl.Workbook()
    workbook.remove(workbook.active)

    sheets = {
        "animals": ["id", "tag_number", "species", "breed", "name", "sex", "date_of_birth", "weight_kg", "status", "status_changed_at"],
        "customers": ["id", "user_id", "name", "phone", "email"],
        "health_records": ["id", "animal_id", "record_type", "description", "medication", "dosage", "cost", "record_date", "next_due_date"],
        "farm_activities": ["id", "activity_type", "description", "animal_id", "assigned_to", "activity_at", "status", "completed_at"],
        "maintenance_records": ["id", "asset_name", "asset_type", "maintenance_date", "description", "cost", "next_due_date"],
        "farm_produce": ["id", "produce_name", "production_date", "production_session", "animal_id", "quantity_produced", "quantity_sold", "quantity_damaged", "unit"],
        "inventory_items": ["id", "name", "category", "unit", "quantity_on_hand", "reorder_level", "unit_cost"],
        "inventory_transactions": ["id", "item_id", "transaction_type", "quantity_change", "unit_cost", "reason", "transaction_date"],
        "products": ["id", "name", "product_type", "species", "unit", "available_quantity", "unit_price"],
        "orders": ["id", "customer_id", "status", "total_amount", "notes", "handled_by", "ordered_at", "accepted_at", "fulfilled_at"],
        "order_items": ["id", "order_id", "product_id", "item_name", "quantity", "unit", "unit_price"],
        "order_animals": ["order_id", "animal_id"],
        "sales": ["id", "order_id", "customer_id", "description", "quantity", "unit", "unit_price", "sold_at"],
        "payments": ["id", "order_id", "customer_id", "amount", "method", "status", "transaction_reference", "phone", "paid_at"],
        "expenses": ["id", "category", "description", "amount", "expense_date"],
        "feedback": ["id", "user_id", "category", "message", "status", "resolved_by", "resolved_at", "created_at"],
        "weather_observations": ["observation_date", "rain_mm", "temp_c"],
    }
    for name, headers in sheets.items():
        sheet = workbook.create_sheet(name)
        sheet.append(headers)

    workbook["customers"].append([1, None, "Synthetic Buyer", "0700000001", "buyer1@sim.flouri.invalid"])
    workbook["animals"].append([1, "SIM-001", "cattle", "Friesian", "Cattle 1", "female", "2024-01-01", 350, "active", None])
    workbook["weather_observations"].append(["2026-01-01", 1.5, 22.0])
    workbook["orders"].append([1, 1, "pending", 150, "placeholder", None, "2026-01-01 10:00:00", None, None])
    workbook["order_items"].append([1, 1, None, "Milk", 2, "litres", 75])
    workbook["sales"].append([1, 1, 1, "Milk sale", 2, "litres", 75, "2026-01-01 15:00:00"])
    workbook["payments"].append([1, 1, 1, 150, "mpesa", "pending", "SIM-PAY", "0700000001", None])

    sql, reset, counts = build_sql(workbook)

    assert "SYNTHETIC DEMONSTRATION" in sql
    assert "NOT_A_REAL_PASSWORD_HASH" not in sql
    assert "id >= 100000" in reset
    assert counts["orders"] == 1
    assert counts["order_items"] == 1
    assert counts["payments"] == 1


def test_customer_ids_are_remapped_without_colliding_with_real_ids():
    mapping = unique_customer_map([
        {"id": 1},
        {"id": 60},
    ])

    assert mapping == {1: 900001, 60: 900060}
