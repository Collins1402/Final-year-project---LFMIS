<?php
declare(strict_types=1);

namespace Lfmis\Services;

final class DuplicateProductionException extends \DomainException
{
    public array $existingRecord;

    public function __construct(string $message, array $existingRecord)
    {
        $this->existingRecord = $existingRecord;
        parent::__construct($message);
    }
}
