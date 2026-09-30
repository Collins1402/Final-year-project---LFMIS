<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class AssistantService
{
    public function __construct(private PDO $db) {}

    public function answer(string $question, array $user): array
    {
        $question = strtolower(trim($question));
        $animalCount = (int) $this->db->query("SELECT COUNT(*) FROM animals WHERE status='active'")->fetchColumn();
        $milk = (float) $this->db->query('SELECT COALESCE(SUM(quantity_litres),0) FROM milk_production WHERE production_date=CURRENT_DATE()')->fetchColumn();
        $openTasks = (int) $this->db->query("SELECT COUNT(*) FROM farm_activities WHERE status='planned'")->fetchColumn();
        $stock = (int) $this->db->query('SELECT COUNT(*) FROM inventory_items WHERE is_active=1 AND quantity_on_hand <= reorder_level')->fetchColumn();
        $healthDue = (int) $this->db->query("SELECT COUNT(*) FROM health_records WHERE next_due_date IS NOT NULL AND next_due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)")->fetchColumn();
        if ($question === '' || preg_match('/^(hi|hello|hey|how are you|how are u|good morning)/', $question)) {
            $answer = 'Hello. I am the Flouri Farm assistant, and I am ready to help with farm records.';
        } elseif (str_contains($question, 'not eating') || str_contains($question, 'sick') || str_contains($question, 'ill') || str_contains($question, 'diagnos')) {
            $answer = 'I cannot diagnose an animal. Record the signs and contact the veterinarian for an examination.';
        } elseif (str_contains($question, 'milk')) {
            $answer = sprintf('The farm has recorded %.2f litres of milk today.', $milk);
        } elseif (str_contains($question, 'animal') || str_contains($question, 'herd')) {
            $answer = sprintf('There are %d active animals in the register.', $animalCount);
        } elseif (str_contains($question, 'task') || str_contains($question, 'activity')) {
            $answer = sprintf('There are %d planned farm activities.', $openTasks);
        } elseif (str_contains($question, 'stock') || str_contains($question, 'feed')) {
            $answer = sprintf('%d inventory item(s) are at or below their reorder level.', $stock);
        } elseif (str_contains($question, 'health') || str_contains($question, 'vaccin')) {
            $answer = sprintf('%d health follow-up(s) are due within seven days.', $healthDue);
        } else {
            $answer = 'Ask about today\'s milk, active animals, open tasks, low stock or health follow-ups.';
        }
        return ['mode' => 'rule-based', 'answer' => $answer, 'role' => $user['role']];
    }
}
