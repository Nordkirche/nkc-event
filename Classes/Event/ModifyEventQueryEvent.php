<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\NkcEvent\Controller\EventController;
use TYPO3\CMS\Extbase\Mvc\Request;

final class ModifyEventQueryEvent
{
    public function __construct(
        private readonly EventController $controller,
        private EventQuery $eventQuery,
        private readonly Request $request,
        private readonly array $settings
    ) {}

    public function getEventQuery(): EventQuery
    {
        return $this->eventQuery;
    }

    public function setEventQuery(EventQuery $eventQuery): void
    {
        $this->eventQuery = $eventQuery;
    }

    public function getController(): EventController
    {
        return $this->controller;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }
}
