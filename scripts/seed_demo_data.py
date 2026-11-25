from __future__ import annotations

import argparse
import math
import random
from datetime import date, datetime, time, timedelta
from pathlib import Path


DEMO_SHARE = 0.30


def demo_count(source_count: int) -> int:
    return max(1, math.ceil(source_count * DEMO_SHARE))


SPECIES = {
    "cattle": (40, "CAT", ["Friesian", "Ayrshire", "Jersey", "Sahiwal"]),
    "goat": (12, "GOA", ["Toggenburg", "Galla", "Saanen"]),
    "sheep": (12, "SHE", ["Dorper", "Merino"]),
    "rabbit": (51, "RAB", ["New Zealand White", "Californian"]),
    "chicken": (101, "CHK", ["Kienyeji", "Kuroiler"]),
}
SIMULATED = "SIMULATED DATA"


def sql_value(value: object) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        return f"{value:.2f}"
    return "'" + str(value).replace("'", "''") + "'"


def add_insert(statements: list[str], table: str, columns: list[str], rows: list[tuple[object, ...]]) -> None:
    if not rows:
        return
    header = f"INSERT INTO {table} ({', '.join(columns)}) VALUES\n"
    for offset in range(0, len(rows), 500):
        values = rows[offset:offset + 500]
        statements.append(header + ",\n".join("(" + ", ".join(sql_value(value) for value in row) + ")" for row in values) + ";")


def generated_rows(days: int, seed: int) -> tuple[list[str], dict[str, int]]:
    rng = random.Random(seed)
    today = date.today()
    first_day = today - timedelta(days=days)
    dates = [first_day + timedelta(days=index) for index in range(days)]
    statements: list[str] = ["SET NAMES utf8mb4;"]
    counts: dict[str, int] = {}

    animals: list[tuple[object, ...]] = []
    animal_ids: dict[str, list[int]] = {species: [] for species in SPECIES}
    sold_animal_ids: list[int] = []
    animal_index = 0
    for species, (count, code, breeds) in SPECIES.items():
        for species_index in range(count):
            animal_id = 1000 + animal_index
            animal_index += 1
            status = ("active" if species_index < 24 else "sold" if species_index < 30 else
                      "transferred" if species_index < 35 else "deceased") if species == "cattle" else "active"
            status_changed = (today - timedelta(days=rng.randint(1, days))).isoformat() if status != "active" else None
            sex = "female" if species_index % 3 else "male"
            if species == "cattle" and species_index < 24:
                sex = "female"
            tag = f"SIM-{code}-{species_index + 1:03d}"
            animals.append((animal_id, tag, species, rng.choice(breeds), f"Demo {species.title()} {species_index + 1:03d}", sex,
                            (today - timedelta(days=rng.randint(180, 2200))).isoformat(),
                            round(rng.uniform(1.2, 540), 2), status,
                            f"{status_changed} 12:00:00" if status_changed else None, SIMULATED, None))
            animal_ids[species].append(animal_id)
            if status == "sold":
                sold_animal_ids.append(animal_id)
    add_insert(statements, "animals", ["id", "tag_number", "species", "breed", "name", "sex", "date_of_birth", "weight_kg", "status", "status_changed_at", "notes", "created_by"], animals)
    counts["animals"] = len(animals)

    weather_rows = []
    produce_rows = []
    dairy_cows = [animal_id for animal_id in animal_ids["cattle"] if animals[animal_id - 1000][5] == "female"][:demo_count(16)]
    for day_index, current in enumerate(dates):
        season = day_index / max(days, 1) * math.tau
        rain = round(max(0, rng.gauss(5 + 4 * (1 + math.sin(season)), 6)), 2)
        temperature = round(rng.gauss(24 + 5 * math.sin(season), 2), 2)
        weather_rows.append((current.isoformat(), rain, temperature, "simulated"))
        for cow_index, animal_id in enumerate(dairy_cows):
            daily_total = max(0.0, 16.4 * (1 + 0.12 * math.sin((day_index + cow_index * 11) / 58)) + rng.uniform(-1.2, 1.2))
            morning = round(daily_total * rng.uniform(0.52, 0.58), 2)
            evening = round(daily_total - morning, 2)
            produce_rows.append(("Milk", current.isoformat(), "morning", animal_id, morning, 0, 0, "litres", SIMULATED, None))
            produce_rows.append(("Milk", current.isoformat(), "evening", animal_id, evening, 0, 0, "litres", SIMULATED, None))
        egg_trays = round(max(0.0, 4.0 + 0.45 * math.sin(day_index / 19) + rng.uniform(-0.8, 0.8)), 2)
        produce_rows.append(("Eggs", current.isoformat(), None, None, egg_trays, 0, 0, "trays", SIMULATED, None))
    add_insert(statements, "weather_observations", ["observation_date", "rain_mm", "temp_c", "source"], weather_rows)
    add_insert(statements, "farm_produce", ["produce_name", "production_date", "production_session", "animal_id", "quantity_produced", "quantity_sold", "quantity_damaged", "unit", "notes", "recorded_by"], produce_rows)
    counts["weather_observations"] = len(weather_rows)
    counts["farm_produce"] = len(produce_rows)

    health_rows = []
    health_types = ["vaccination", "treatment", "deworming", "checkup", "illness", "other"]
    all_animal_ids = [animal_id for species_animals in animal_ids.values() for animal_id in species_animals]
    for _ in range(demo_count(2300)):
        record_day = first_day + timedelta(days=rng.randrange(days))
        due_date = record_day + timedelta(days=rng.randint(30, 180)) if rng.random() < 0.32 else None
        health_rows.append((rng.choice(all_animal_ids), rng.choice(health_types),
                            f"Routine care record [{SIMULATED.lower()}]", rng.choice(["Vaccine", "Mineral supplement", None]),
                            None, round(rng.uniform(100, 3500), 2), record_day.isoformat(), due_date.isoformat() if due_date else None, None))
    add_insert(statements, "health_records", ["animal_id", "record_type", "description", "medication", "dosage", "cost", "record_date", "next_due_date", "recorded_by"], health_rows)
    counts["health_records"] = len(health_rows)

    activity_rows = []
    for index in range(demo_count(374)):
        activity_day = first_day + timedelta(days=rng.randrange(days))
        status = "planned" if index % 7 == 0 else "completed"
        at = datetime.combine(activity_day, time(hour=rng.randrange(5, 18), minute=0))
        activity_rows.append((rng.choice(["feeding", "milking", "cleaning", "health", "maintenance", "other"]),
                              f"Scheduled farm activity {index + 1} [{SIMULATED.lower()}]", None, None,
                              at.strftime("%Y-%m-%d %H:%M:%S"), status,
                              at.strftime("%Y-%m-%d %H:%M:%S") if status == "completed" else None, None, None))
    add_insert(statements, "farm_activities", ["activity_type", "description", "animal_id", "assigned_to", "activity_at", "status", "completed_at", "completed_by", "created_by"], activity_rows)
    counts["farm_activities"] = len(activity_rows)

    maintenance_rows = []
    for index in range(demo_count(19)):
        maintenance_day = first_day + timedelta(days=rng.randrange(days))
        maintenance_rows.append((f"SIM-Asset-{index + 1:02d}", rng.choice(["equipment", "housing", "water", "vehicle", "other"]),
                                 maintenance_day.isoformat(), f"Routine inspection [{SIMULATED.lower()}]", round(rng.uniform(500, 12000), 2),
                                 (maintenance_day + timedelta(days=180)).isoformat(), SIMULATED, None))
    add_insert(statements, "maintenance_records", ["asset_name", "asset_type", "maintenance_date", "description", "cost", "next_due_date", "notes", "recorded_by"], maintenance_rows)
    counts["maintenance_records"] = len(maintenance_rows)

    inventory_specs = [
        ("SIM-Hay bales", "feed", "bales", 60, 30, 850),
        ("SIM-Dairy meal", "feed", "kg", 45, 20, 72),
        ("SIM-Mineral salt", "supplies", "kg", 30, 15, 180),
        ("SIM-Veterinary gloves", "medicine", "boxes", 20, 12, 650),
        ("SIM-Disinfectant", "supplies", "litres", 3, 10, 420),
        ("SIM-Poultry feed", "feed", "bags", 2, 8, 2450),
        ("SIM-Water troughs", "equipment", "units", 4, 5, 1800),
    ][:demo_count(7)]
    inventory_rows = []
    transaction_rows = []
    for index, (name, category, unit, target, reorder, cost) in enumerate(inventory_specs):
        item_id = 1000 + index
        balance = 0.0
        changes = [("in", round(rng.uniform(120, 220), 2))]
        balance += changes[0][1]
        for _ in range(demo_count(119)):
            if balance < 20 or rng.random() < 0.43:
                change = round(rng.uniform(4, 24), 2)
                changes.append(("in", change))
                balance += change
            else:
                change = -round(min(balance - 1, rng.uniform(2, 14)), 2)
                changes.append(("out", change))
                balance += change
        final_target = float(target) + (0.01 if round(balance, 2) == target else 0)
        changes.append(("adjustment", round(final_target - balance, 2)))
        inventory_rows.append((item_id, name, category, unit, final_target, reorder, cost, 1))
        for transaction_index, (kind, change) in enumerate(changes):
            transaction_day = first_day + timedelta(days=transaction_index % days)
            transaction_rows.append((item_id, kind, change, cost, f"Stock {kind} [{SIMULATED.lower()}]",
                                     f"{transaction_day.isoformat()} 09:00:00", None))
    add_insert(statements, "inventory_items", ["id", "name", "category", "unit", "quantity_on_hand", "reorder_level", "unit_cost", "is_active"], inventory_rows)
    add_insert(statements, "inventory_transactions", ["item_id", "transaction_type", "quantity_change", "unit_cost", "reason", "transaction_date", "recorded_by"], transaction_rows)
    counts["inventory_items"] = len(inventory_rows)
    counts["inventory_transactions"] = len(transaction_rows)

    customers = []
    for index in range(demo_count(35)):
        customers.append((1000 + index, None, f"Demo Customer {index + 1:02d}", f"07{rng.randrange(100000000):08d}", f"customer{index + 1:02d}@sim.flouri.invalid"))
    add_insert(statements, "customers", ["id", "user_id", "name", "phone", "email"], customers)
    counts["customers"] = len(customers)

    orders = []
    order_items = []
    order_animals = []
    sales = []
    payments = []
    order_count = demo_count(569)
    fulfilled_count = order_count - demo_count(8)
    for index in range(order_count):
        order_id = 1000 + index
        customer_id = 1000 + rng.randrange(10)
        order_day = first_day + timedelta(days=rng.randrange(days))
        if index < len(sold_animal_ids):
            item_name, quantity, unit, unit_price = "Livestock", 1.0, "head", round(rng.uniform(9000, 75000), 2)
            order_animals.append((order_id, sold_animal_ids[index]))
        else:
            item_name, unit = rng.choice([("Milk", "litres"), ("Eggs", "trays"), ("Wool", "kg")])
            quantity = round(rng.uniform(1, 20), 2)
            unit_price = round(rng.uniform(180, 900), 2)
        total = round(quantity * unit_price, 2)
        if index < fulfilled_count:
            status = "fulfilled"
        else:
            status = ["pending", "quoted", "accepted"][index - fulfilled_count]
        ordered_at = f"{order_day.isoformat()} 10:00:00"
        accepted_at = ordered_at if status in ("accepted", "fulfilled") else None
        fulfilled_at = ordered_at if status == "fulfilled" else None
        orders.append((order_id, customer_id, status, total, f"Demo order [{SIMULATED.lower()}]", None, ordered_at, accepted_at, fulfilled_at))
        order_items.append((order_id, None, item_name, quantity, unit, unit_price))
        if status == "fulfilled":
            sales.append((order_id, customer_id, f"{item_name} order #{order_id} [{SIMULATED.lower()}]", quantity, unit, unit_price, ordered_at, None))
            payments.append((order_id, customer_id, total, rng.choice(["cash", "mpesa", "bank"]), "confirmed", None, ordered_at, None))
        elif status == "accepted":
            payments.append((order_id, customer_id, round(total / 2, 2), "mpesa", "pending", None, None, None))
    add_insert(statements, "orders", ["id", "customer_id", "status", "total_amount", "notes", "handled_by", "ordered_at", "accepted_at", "fulfilled_at"], orders)
    add_insert(statements, "order_items", ["order_id", "product_id", "item_name", "quantity", "unit", "unit_price"], order_items)
    add_insert(statements, "order_animals", ["order_id", "animal_id"], order_animals)
    add_insert(statements, "sales", ["order_id", "customer_id", "description", "quantity", "unit", "unit_price", "sold_at", "recorded_by"], sales)
    add_insert(statements, "payments", ["order_id", "customer_id", "amount", "method", "status", "transaction_reference", "paid_at", "recorded_by"], payments)
    counts["orders"] = len(orders)
    counts["order_items"] = len(order_items)
    counts["order_animals"] = len(order_animals)
    counts["sales"] = len(sales)
    counts["payments"] = len(payments)

    expenses = []
    for index in range(demo_count(230)):
        expense_day = first_day + timedelta(days=rng.randrange(days))
        expenses.append((rng.choice(["feed", "veterinary", "labour", "equipment", "utilities", "transport", "maintenance", "other"]),
                         f"Farm operating cost {index + 1} [{SIMULATED.lower()}]", round(rng.uniform(300, 18000), 2), expense_day.isoformat(), None))
    add_insert(statements, "expenses", ["category", "description", "amount", "expense_date", "recorded_by"], expenses)
    counts["expenses"] = len(expenses)
    return statements, counts


def reset_sql() -> str:
    return """DELETE FROM payments WHERE customer_id IN (SELECT id FROM customers WHERE email LIKE '%@sim.flouri.invalid') OR order_id IN (SELECT id FROM orders WHERE notes LIKE '%[simulated]');
DELETE FROM sales WHERE description LIKE '%[simulated]' OR customer_id IN (SELECT id FROM customers WHERE email LIKE '%@sim.flouri.invalid');
DELETE FROM order_animals WHERE order_id IN (SELECT id FROM orders WHERE notes LIKE '%[simulated]') OR animal_id IN (SELECT id FROM animals WHERE tag_number LIKE 'SIM-%');
DELETE FROM orders WHERE notes LIKE '%[simulated]' OR customer_id IN (SELECT id FROM customers WHERE email LIKE '%@sim.flouri.invalid');
DELETE FROM order_items WHERE item_name LIKE '%[simulated]';
DELETE FROM customers WHERE email LIKE '%@sim.flouri.invalid';
DELETE FROM expenses WHERE description LIKE '%[simulated]';
DELETE FROM inventory_transactions WHERE reason LIKE '%[simulated]';
DELETE FROM inventory_items WHERE name LIKE 'SIM-%';
DELETE FROM maintenance_records WHERE notes = 'SIMULATED DATA';
DELETE FROM farm_activities WHERE description LIKE '%[simulated]';
DELETE FROM health_records WHERE description LIKE '%[simulated]';
DELETE FROM farm_produce WHERE notes = 'SIMULATED DATA';
DELETE FROM weather_observations WHERE source = 'simulated';
DELETE FROM animals WHERE tag_number LIKE 'SIM-%';
"""


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate clearly labelled LFMIS demo data as SQL.")
    parser.add_argument("--days", type=int, default=730, help="Consecutive history days ending yesterday (default: 730).")
    parser.add_argument("--seed", type=int, default=42, help="Random seed for repeatable output (default: 42).")
    parser.add_argument("--output-dir", type=Path, default=Path("seed_data"), help="Directory for generated SQL files.")
    args = parser.parse_args()
    if args.days < 21:
        parser.error("--days must be at least 21 to support the milk forecast.")

    statements, counts = generated_rows(args.days, args.seed)
    args.output_dir.mkdir(parents=True, exist_ok=True)
    seed_path = args.output_dir / "seed.sql"
    reset_path = args.output_dir / "reset.sql"
    seed_path.write_text("\n\n".join(statements) + "\n", encoding="utf-8")
    reset_path.write_text(reset_sql(), encoding="utf-8")
    print(f"Wrote {seed_path} and {reset_path}")
    print(f"Demo share: {DEMO_SHARE:.0%} of the original entity and record counts; generated values are labelled SIMULATED DATA. The {args.days}-day production history remains consecutive so forecasts can be evaluated.")
    for name, count in counts.items():
        print(f"{name}: {count}")


if __name__ == "__main__":
    main()
