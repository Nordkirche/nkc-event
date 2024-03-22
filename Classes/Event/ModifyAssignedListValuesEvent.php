<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use Nordkirche\NkcEvent\Controller\EventController;
use TYPO3\CMS\Extbase\Mvc\Request;

final class ModifyAssignedListValuesEvent
{
    public function __construct(
        private readonly EventController $controller,
        private array $assignedListValues,
        private readonly Request $request
    ) {}

    public function getAssignedListValues(): array
    {
        return $this->assignedListValues;
    }

    public function setAssignedListValues(array $assignedListValues): void
    {
        $this->assignedListValues = $assignedListValues;
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
