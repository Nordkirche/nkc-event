<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\NkcEvent\Controller\EventController;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

final class ModifyEventQueryEvent
{
    public function __construct(
        private readonly EventController $controller,
        private EventQuery $eventQuery,
        private readonly RequestInterface $request,
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

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }
}
