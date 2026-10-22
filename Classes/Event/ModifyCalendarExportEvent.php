<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Event;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

/**
 * iCalendar export of an event, before the file is sent
 */
final class ModifyCalendarExportEvent
{
    public function __construct(
        private readonly Event $event,
        private string $calendar,
        private string $fileName,
        private readonly RequestInterface $request
    ) {}

    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * Content of the .ics file (RFC 5545, lines end with CRLF)
     */
    public function getCalendar(): string
    {
        return $this->calendar;
    }

    public function setCalendar(string $calendar): void
    {
        $this->calendar = $calendar;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
