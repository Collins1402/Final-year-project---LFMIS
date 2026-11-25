<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class DemoService
{
    public function __construct(private PDO $db) {}

    public function seed(int $userId): array
    {
        $existingAnimals = (int) $this->db->query('SELECT COUNT(*) FROM animals')->fetchColumn();
        $existingProduce = (int) $this->db->query('SELECT COUNT(*) FROM farm_produce')->fetchColumn();
        if ($existingAnimals > 0 || $existingProduce > 0) {
            return ['created' => false, 'message' => 'Demo data can only be loaded when the animal register and produce records are empty.'];
        }
        $this->db->beginTransaction();
        try {
            $animals = [
                ['DEMO-COW-001','cattle','Friesian','Daisy','female','2021-03-14',480,'sold'],
                ['DEMO-COW-002','cattle','Ayrshire','Bella','female','2020-08-02',455,'sold'],
                ['DEMO-COW-003','cattle','Jersey','Luna','female','2022-01-20',410,'sold'],
                ['DEMO-GOAT-001','goat','Toggenburg','Neema','female','2023-02-05',42,'active'],
                ['DEMO-GOAT-002','goat','Galla','Zawadi','female','2023-06-18',38,'active'],
                ['DEMO-RAB-001','rabbit','New Zealand White','Clover','female','2025-10-03',4.2,'active'],
                ['DEMO-CHK-001','chicken','Kienyeji','Sunny','female','2025-09-10',2.1,'active'],
                ['DEMO-CHK-002','chicken','Kienyeji','Pepper','female','2025-09-10',2.0,'active'],
            ];
            $animalStatement = $this->db->prepare('INSERT INTO animals (tag_number,species,breed,name,sex,date_of_birth,weight_kg,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $animalIds = [];
            foreach ($animals as $animal) {
                $animalStatement->execute([...$animal, 'TEST DATA - demo record', $userId]);
                $animalIds[] = (int) $this->db->lastInsertId();
            }
            $produce = $this->db->prepare('INSERT INTO farm_produce (produce_name,production_date,production_session,quantity_produced,quantity_sold,quantity_damaged,unit,notes,recorded_by) VALUES (?,?,?,?,0,0,?,?,?)');
            for ($day = 27; $day >= 0; $day--) {
                $date = date('Y-m-d', strtotime("-{$day} days"));
                $produce->execute(['Milk', $date, 'morning', round(30 + sin($day * 0.7) * 2, 2), 'litres', 'TEST DATA - demo record', $userId]);
                $produce->execute(['Milk', $date, 'evening', round(24 + sin($day * 0.8 + 1) * 1.5, 2), 'litres', 'TEST DATA - demo record', $userId]);
                $produce->execute(['Eggs', $date, null, 18 + ($day % 4), 'eggs', 'TEST DATA - demo record', $userId]);
            }
            $health = $this->db->prepare('INSERT INTO health_records (animal_id,record_type,description,record_date,next_due_date,recorded_by) VALUES (?,?,?,?,?,?)');
            $health->execute([$animalIds[0], 'vaccination', 'Routine cattle vaccination - TEST DATA', date('Y-m-d'), date('Y-m-d', strtotime('+5 days')), $userId]);
            $health->execute([$animalIds[3], 'deworming', 'Routine goat deworming - TEST DATA', date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+10 days')), $userId]);
            $activity = $this->db->prepare('INSERT INTO farm_activities (activity_type,description,activity_at,status,created_by) VALUES (?,?,?,?,?)');
            $activity->execute(['feeding', 'Morning feed check - TEST DATA', date('Y-m-d H:i:s'), 'planned', $userId]);
            $activity->execute(['cleaning', 'Clean poultry housing - TEST DATA', date('Y-m-d H:i:s', strtotime('+2 hours')), 'planned', $userId]);
            $inventory = $this->db->prepare('INSERT INTO inventory_items (name,category,unit,quantity_on_hand,reorder_level,unit_cost) VALUES (?,?,?,?,?,?)');
            $inventory->execute(['Demo hay bales','feed','bales',12,5,850]);
            $inventory->execute(['Demo mineral salt','supplies','kg',3,5,180]);
            $this->db->commit();
            return ['created' => true, 'animals' => count($animals), 'productionDays' => 28, 'message' => 'Demo data loaded and labelled TEST DATA.'];
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
