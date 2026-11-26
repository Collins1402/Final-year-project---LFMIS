<?php
/**
 * File purpose: implements DuplicateProductionException for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

final class DuplicateProductionException extends \DomainException
{
    public array $existingRecord;

    /**
 * Connects this service to its required dependency.
 * @param string $message Input used by this operation.
 * @param array $existingRecord Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(string $message, array $existingRecord)
    {
        $this->existingRecord = $existingRecord;
        parent::__construct($message);
    }
}
