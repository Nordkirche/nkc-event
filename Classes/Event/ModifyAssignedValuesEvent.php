<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use Nordkirche\NkcEvent\Controller\EventController;
use TYPO3\CMS\Extbase\Mvc\Request;

final class ModifyAssignedValuesEvent
{
    public function __construct(
        private readonly EventController $controller,
        private array $assignedValues,
        private readonly Request $request
    ) {}

    public function getAssignedValues(): array
    {
        return $this->assignedValues;
    }

    public function setAssignedValues(array $assignedValues): void
    {
        $this->assignedValues = $assignedValues;
    }

    public function getController(): EventController
    {
        return $this->controller;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
