<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\NkcEvent\Event\ModifyCalendarExportEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

/**
 * iCalendar export of events (RFC 5545)
 */
class ExportService
{
    private const EOL = "\r\n";
    private const PRODUCT_ID = '-//Nordkirche//nkc_event//DE';
    private const TIMEZONE = 'Europe/Berlin';

    public function __construct(private readonly ?EventDispatcherInterface $eventDispatcher = null) {}

    /**
     * .ics file of an event, after ModifyCalendarExportEvent
     *
     * @return array{calendar: string, fileName: string}
     */
    public function export(Event $event, RequestInterface $request): array
    {
        $export = new ModifyCalendarExportEvent($event, $this->renderCalendar([$event]), 'event-' . $event->getId() . '.ics', $request);
        if ($this->eventDispatcher !== null) {
            /** @var ModifyCalendarExportEvent $export */
            $export = $this->eventDispatcher->dispatch($export);
        }

        // The name ends up in the Content-Disposition header
        $fileName = trim(str_replace(['"', '\\', '/', "\r", "\n"], '', $export->getFileName()));

        return [
            'calendar' => $export->getCalendar(),
            'fileName' => $fileName !== '' ? $fileName : 'event.ics',
        ];
    }

    /**
     * @param iterable<Event> $events
     */
    public function renderCalendar(iterable $events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODUCT_ID, 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        foreach ($events as $event) {
            if ($event instanceof Event) {
                array_push($lines, ...$this->renderEvent($event));
            }
        }
        $lines[] = 'END:VCALENDAR';

        return implode(self::EOL, array_map($this->fold(...), $lines)) . self::EOL;
    }

    /**
     * @return string[]
     */
    private function renderEvent(Event $event): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $this->toUtc($this->read(static fn() => $event->getStartsAt()));
        $end = $this->toUtc($this->read(static fn() => $event->getEndsAt())) ?? ($start ? (clone $start)->add(new \DateInterval('PT1H')) : null);
        if ($start !== null && $end !== null && $end <= $start) {
            $end = (clone $start)->add(new \DateInterval('PT1H'));
        }

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $this->getUniqueIdentifier($event),
            'DTSTAMP:' . (new \DateTime('now', $utc))->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape((string)$this->read(static fn() => $event->getTitle())),
        ];
        if ($start !== null) {
            $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
        }
        if ($end !== null) {
            $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
        }
        $location = (string)$this->read(static fn() => $event->getLocationName());
        if ($location !== '') {
            $lines[] = 'LOCATION:' . $this->escape($location);
        }
        $description = (string)$this->read(static fn() => $event->getDescription());
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . $this->escape($description);
        }
        $lines[] = 'CLASS:PUBLIC';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * NDK getters with non-nullable return types fail on missing values
     */
    private function read(\Closure $getter): mixed
    {
        try {
            return $getter();
        } catch (\TypeError) {
            return null;
        }
    }

    private function toUtc(mixed $date): ?\DateTime
    {
        if (!$date instanceof \DateTimeInterface) {
            return null;
        }
        // The NAPI delivers local times
        $local = new \DateTime($date->format('Y-m-d H:i:s'), new \DateTimeZone(self::TIMEZONE));
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Text values: plain text, backslash, comma, semicolon and line breaks escaped
     */
    private function escape(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], trim($text));
    }

    /**
     * Lines longer than 75 octets are folded (continuation lines start with a space)
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $current = '';
        foreach (mb_str_split($line) as $character) {
            if (strlen($current . $character) > ($parts === [] ? 75 : 74)) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $character;
        }
        $parts[] = $current;
        return implode(self::EOL . ' ', $parts);
    }

    private function getUniqueIdentifier(Event $event): string
    {
        return md5($event->getId() . ':' . ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '')) . '@nkc_event';
    }
}
