<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;
use PDOException;

final class AnimalService
{
    public function __construct(private PDO $db)
    {
    }

    public function list(): array
    {
        return $this->db->query(
            'SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, status_changed_at, notes, created_at
             FROM animals ORDER BY status = "active" DESC, tag_number'
        )->fetchAll();
    }

    public function create(array $input, int $userId): array
    {
        $tag = trim((string) ($input['tagNumber'] ?? ''));
        $species = (string) ($input['species'] ?? '');
        $sex = (string) ($input['sex'] ?? '');
        $allowedSpecies = ['cattle', 'goat', 'rabbit', 'chicken', 'sheep', 'other'];
        if ($tag === '' || strlen($tag) > 40 || !in_array($species, $allowedSpecies, true) || !in_array($sex, ['female', 'male'], true)) {
            throw new \InvalidArgumentException('Enter a tag number, supported species and sex.');
        }
        $weight = $input['weightKg'] ?? null;
        if ($weight !== null && (!is_numeric($weight) || (float) $weight <= 0)) {
            throw new \InvalidArgumentException('Weight must be a positive number.');
        }
        $tagCheck = $this->db->prepare('SELECT 1 FROM animals WHERE tag_number = ? LIMIT 1');
        $tagCheck->execute([$tag]);
        if ($tagCheck->fetchColumn()) {
            throw new \DomainException("Tag {$tag} is already registered.");
        }
        $dateOfBirth = $this->dateOrNull($input['dateOfBirth'] ?? null);
        $statement = $this->db->prepare(
            'INSERT INTO animals (tag_number, species, breed, name, sex, date_of_birth, weight_kg, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $tag, $species, $this->textOrNull($input['breed'] ?? null), $this->textOrNull($input['name'] ?? null), $sex,
                $dateOfBirth, $weight === null ? null : (float) $weight, $this->textOrNull($input['notes'] ?? null), $userId,
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' || (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new \DomainException("Tag {$tag} is already registered.");
            }
            throw $error;
        }
        return $this->find((int) $this->db->lastInsertId());
    }

    public function updateStatus(int $id, string $status): array
    {
        if (!in_array($status, ['active', 'sold', 'deceased', 'transferred'], true)) {
            throw new \InvalidArgumentException('Unsupported animal status.');
        }
        $exists = $this->db->prepare('SELECT 1 FROM animals WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) throw new \RuntimeException('Animal not found.');
        $statement = $this->db->prepare('UPDATE animals SET status = ?, status_changed_at = NOW() WHERE id = ?');
        $statement->execute([$status, $id]);
        return $this->find($id);
    }

    public function updateProfile(int $id, array $input): array
    {
        $animal = $this->find($id);
        $tag = trim((string) ($input['tagNumber'] ?? ''));
        $species = (string) ($input['species'] ?? '');
        $sex = (string) ($input['sex'] ?? '');
        if ($tag === '' || strlen($tag) > 40 || !in_array($species, ['cattle', 'goat', 'rabbit', 'chicken', 'sheep', 'other'], true) || !in_array($sex, ['female', 'male'], true)) {
            throw new \InvalidArgumentException('Enter a tag number, supported species and sex.');
        }

        $tagCheck = $this->db->prepare('SELECT 1 FROM animals WHERE tag_number = ? AND id <> ? LIMIT 1');
        $tagCheck->execute([$tag, $id]);
        if ($tagCheck->fetchColumn()) throw new \DomainException("Tag {$tag} is already registered.");

        if ($species !== $animal['species']) {
            $linked = $this->db->prepare('SELECT 1 FROM order_animals WHERE animal_id = ? LIMIT 1');
            $linked->execute([$id]);
            if ($linked->fetchColumn()) throw new \DomainException('Species cannot be changed after this animal has been linked to an order.');
        }

        $name = $this->limitedTextOrNull($input['name'] ?? null, 80, 'Name');
        $breed = $this->limitedTextOrNull($input['breed'] ?? null, 80, 'Breed');
        $notes = $this->limitedTextOrNull($input['notes'] ?? null, 500, 'Notes');
        $dateOfBirth = $this->dateOrNull($input['dateOfBirth'] ?? null);
        $weightInput = $input['weightKg'] ?? null;
        $weight = $weightInput === null || trim((string) $weightInput) === '' ? null : filter_var($weightInput, FILTER_VALIDATE_FLOAT);
        if ($weight !== null && ($weight === false || !is_finite((float) $weight) || (float) $weight <= 0)) {
            throw new \InvalidArgumentException('Weight must be a positive number.');
        }

        $statement = $this->db->prepare(
            'UPDATE animals SET tag_number = ?, species = ?, name = ?, breed = ?, sex = ?, date_of_birth = ?, weight_kg = ?, notes = ? WHERE id = ?'
        );
        try {
            $statement->execute([$tag, $species, $name, $breed, $sex, $dateOfBirth, $weight, $notes, $id]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' || (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new \DomainException("Tag {$tag} is already registered.");
            }
            throw $error;
        }
        return $this->find($id);
    }

    public function find(int $id): array
    {
        $statement = $this->db->prepare('SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, status_changed_at, notes, created_at FROM animals WHERE id = ?');
        $statement->execute([$id]);
        $animal = $statement->fetch();
        if (!$animal) {
            throw new \RuntimeException('Animal not found.');
        }
        return $animal;
    }

    public function profile(int $id): array
    {
        $animal = $this->find($id);
        $health = $this->db->prepare('SELECT id, record_type, description, medication, dosage, cost, record_date, next_due_date FROM health_records WHERE animal_id = ? ORDER BY record_date DESC, id DESC LIMIT 100');
        $health->execute([$id]);
        $production = $this->db->prepare('SELECT id, produce_name, production_date, production_session, quantity_produced, quantity_sold, quantity_damaged, unit FROM farm_produce WHERE animal_id = ? ORDER BY production_date DESC, id DESC LIMIT 100');
        $production->execute([$id]);
        $activities = $this->db->prepare('SELECT id, activity_type, description, activity_at, status, completed_at FROM farm_activities WHERE animal_id = ? ORDER BY activity_at DESC, id DESC LIMIT 100');
        $activities->execute([$id]);
        $orders = $this->db->prepare('SELECT o.id, o.status, o.total_amount, o.ordered_at, o.fulfilled_at, c.name AS customer_name FROM order_animals oa JOIN orders o ON o.id = oa.order_id LEFT JOIN customers c ON c.id = o.customer_id WHERE oa.animal_id = ? ORDER BY o.ordered_at DESC, o.id DESC');
        $orders->execute([$id]);
        return ['animal' => $animal, 'healthRecords' => $health->fetchAll(), 'productionRecords' => $production->fetchAll(), 'activities' => $activities->fetchAll(), 'orders' => $orders->fetchAll()];
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : substr($text, 0, 500);
    }

    private function limitedTextOrNull(mixed $value, int $limit, string $label): ?string
    {
        $text = trim((string) $value);
        if (strlen($text) > $limit) throw new \InvalidArgumentException($label . ' must be ' . $limit . ' characters or fewer.');
        return $text === '' ? null : $text;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $date = date_create((string) $value);
        if (!$date) {
            throw new \InvalidArgumentException('Date of birth must be a valid date.');
        }
        return $date->format('Y-m-d');
    }
}
