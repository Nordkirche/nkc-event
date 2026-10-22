<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\NkcEvent\Event\ModifyCalendarExportEvent;
use Nordkirche\NkcEvent\Service\ExportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExportServiceTest extends UnitTestCase
{
    private ExportService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-key';
        $this->subject = new ExportService();
    }

    #[Test]
    public function calendarHasTheRequiredStructure(): void
    {
        $lines = $this->render($this->createEvent());

        self::assertSame(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nordkirche//nkc_event//DE'], array_slice($lines, 0, 3));
        self::assertSame('END:VCALENDAR', end($lines));
        self::assertContains('BEGIN:VEVENT', $lines);
        self::assertContains('END:VEVENT', $lines);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}@nkc_event$/', (string)$this->property($lines, 'UID'));
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', (string)$this->property($lines, 'DTSTAMP'));
        self::assertSame('Gottesdienst', $this->property($lines, 'SUMMARY'));
        self::assertSame('St. Marien', $this->property($lines, 'LOCATION'));
    }

    #[Test]
    public function linesEndWithCrLf(): void
    {
        $calendar = $this->subject->renderCalendar([$this->createEvent()]);

        self::assertStringEndsWith("END:VCALENDAR\r\n", $calendar);
        self::assertSame(0, preg_match("/(?<!\r)\n/", $calendar), 'No bare line feeds');
    }

    #[Test]
    public function localTimesAreConvertedToUtc(): void
    {
        $summer = $this->render($this->createEvent('2026-07-01 10:00:00', '2026-07-01 11:30:00'));
        $winter = $this->render($this->createEvent('2026-12-24 18:00:00', '2026-12-24 19:00:00'));

        self::assertSame('20260701T080000Z', $this->property($summer, 'DTSTART'));
        self::assertSame('20260701T093000Z', $this->property($summer, 'DTEND'));
        self::assertSame('20261224T170000Z', $this->property($winter, 'DTSTART'));
    }

    #[Test]
    public function anEndBeforeTheStartIsReplacedByOneHour(): void
    {
        $lines = $this->render($this->createEvent('2026-07-01 10:00:00', '2026-07-01 09:00:00'));

        self::assertSame('20260701T090000Z', $this->property($lines, 'DTEND'));
    }

    #[Test]
    public function missingValuesAreLeftOut(): void
    {
        $event = new Event();
        $event->setId('5');
        $event->setTitle('Ohne Termin');

        $lines = $this->render($event);

        self::assertSame('Ohne Termin', $this->property($lines, 'SUMMARY'));
        self::assertNull($this->property($lines, 'DTSTART'));
        self::assertNull($this->property($lines, 'DTEND'));
        self::assertNull($this->property($lines, 'LOCATION'));
        self::assertNull($this->property($lines, 'DESCRIPTION'));
    }

    #[Test]
    public function textIsPlainAndEscaped(): void
    {
        $event = $this->createEvent();
        $event->setTitle('Konzert; Chor, Orgel \\ Bläser');
        $event->setDescription("<p>Eintritt <strong>frei</strong> &amp; offen</p>\r\nZweite Zeile");

        $lines = $this->render($event);

        self::assertSame('Konzert\\; Chor\\, Orgel \\\\ Bläser', $this->property($lines, 'SUMMARY'));
        self::assertSame('Eintritt frei & offen\\nZweite Zeile', $this->property($lines, 'DESCRIPTION'));
    }

    #[Test]
    public function longLinesAreFoldedWithoutBreakingCharacters(): void
    {
        $event = $this->createEvent();
        $event->setDescription(str_repeat('Gemeindefest mit Kaffee, Kuchen und Spielen für Groß und Klein. ', 8));

        $calendar = $this->subject->renderCalendar([$event]);
        $physicalLines = explode("\r\n", rtrim($calendar, "\r\n"));

        foreach ($physicalLines as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'At most 75 octets per line: ' . $line);
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'Multibyte characters are not split: ' . $line);
        }
        self::assertGreaterThan(count($this->render($event)), count($physicalLines), 'Long lines are folded');
        self::assertStringContainsString('für Groß und Klein', $this->property($this->render($event), 'DESCRIPTION'));
    }

    #[Test]
    public function multipleEventsAreOneCalendar(): void
    {
        $second = $this->createEvent();
        $second->setId('99');

        $lines = $this->render($this->createEvent(), $second, 'no event');

        self::assertCount(1, array_keys($lines, 'BEGIN:VCALENDAR', true));
        self::assertCount(2, array_keys($lines, 'BEGIN:VEVENT', true));
        $uids = array_values(array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'UID:')));
        self::assertNotSame($uids[0], $uids[1]);
    }

    #[Test]
    public function theUidIsStableButDoesNotExposeTheId(): void
    {
        $first = $this->property($this->render($this->createEvent()), 'UID');
        $second = $this->property($this->render($this->createEvent()), 'UID');

        self::assertSame($first, $second);
        self::assertStringNotContainsString('1467188', (string)$first);
    }

    #[Test]
    public function exportWithoutListenerDeliversTheCalendar(): void
    {
        $event = $this->createEvent();

        $export = $this->subject->export($event, self::createStub(RequestInterface::class));

        self::assertSame('event-1467188.ics', $export['fileName']);
        self::assertSame($this->subject->renderCalendar([$event]), $export['calendar']);
    }

    #[Test]
    public function listenersCanChangeCalendarAndFileName(): void
    {
        $event = $this->createEvent();
        $request = self::createStub(RequestInterface::class);
        $dispatched = null;
        $subject = $this->createSubjectWithListener(static function (ModifyCalendarExportEvent $export) use (&$dispatched): void {
            $dispatched = $export;
            $export->setCalendar(str_replace('METHOD:PUBLISH', 'METHOD:REQUEST', $export->getCalendar()));
            $export->setFileName('gottesdienst.ics');
        });

        $result = $subject->export($event, $request);

        self::assertInstanceOf(ModifyCalendarExportEvent::class, $dispatched);
        self::assertSame($event, $dispatched->getEvent());
        self::assertSame($request, $dispatched->getRequest());
        self::assertSame('gottesdienst.ics', $result['fileName']);
        self::assertStringContainsString("METHOD:REQUEST\r\n", $result['calendar']);
        self::assertStringNotContainsString('METHOD:PUBLISH', $result['calendar']);
    }

    public static function unsafeFileNameDataProvider(): \Generator
    {
        yield 'quotes' => ['"termin".ics', 'termin.ics'];
        yield 'header injection' => ["termin.ics\r\nX-Evil: 1", 'termin.icsX-Evil: 1'];
        yield 'path' => ['../../termin.ics', '....termin.ics'];
        yield 'empty' => ['  ', 'event.ics'];
    }

    #[Test]
    #[DataProvider('unsafeFileNameDataProvider')]
    public function fileNamesOfListenersAreMadeSafeForTheHeader(string $fileName, string $expected): void
    {
        $subject = $this->createSubjectWithListener(static function (ModifyCalendarExportEvent $export) use ($fileName): void {
            $export->setFileName($fileName);
        });

        self::assertSame($expected, $subject->export($this->createEvent(), self::createStub(RequestInterface::class))['fileName']);
    }

    private function createSubjectWithListener(\Closure $listener): ExportService
    {
        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use ($listener): object {
            $listener($event);
            return $event;
        });
        return new ExportService($dispatcher);
    }

    private function createEvent(string $start = '2026-07-01 10:00:00', string $end = '2026-07-01 12:00:00'): Event
    {
        $event = new Event();
        $event->setId('1467188');
        $event->setTitle('Gottesdienst');
        $event->setStartsAt($start);
        $event->setEndsAt($end);
        $event->setLocationName('St. Marien');
        return $event;
    }

    /**
     * Logical lines of the calendar (folded lines joined)
     *
     * @return string[]
     */
    private function render(mixed ...$events): array
    {
        $calendar = $this->subject->renderCalendar($events);
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $calendar), "\r\n"));
    }

    private function property(array $lines, string $name): ?string
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, $name . ':')) {
                return substr($line, strlen($name) + 1);
            }
        }
        return null;
    }
}
