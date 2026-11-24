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
            'SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, notes, created_at
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
        $statement = $this->db->prepare('UPDATE animals SET status = ?, status_changed_at = NOW() WHERE id = ?');
        $statement->execute([$status, $id]);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException('Animal not found.');
        }
        return $this->find($id);
    }

    public function find(int $id): array
    {
        $statement = $this->db->prepare('SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, notes, created_at FROM animals WHERE id = ?');
        $statement->execute([$id]);
        $animal = $statement->fetch();
        if (!$animal) {
            throw new \RuntimeException('Animal not found.');
        }
        return $animal;
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : substr($text, 0, 500);
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
