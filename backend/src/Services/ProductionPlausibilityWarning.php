<?php
/**
 * File purpose: implements ProductionPlausibilityWarning for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

final class ProductionPlausibilityWarning extends \InvalidArgumentException
{
}
