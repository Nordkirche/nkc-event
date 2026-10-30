<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\NkcEvent\Service\CalendarBuilder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CalendarBuilderTest extends UnitTestCase
{
    private CalendarBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('Europe/Berlin');
        $this->subject = new CalendarBuilder();
    }

    #[Test]
    public function weeksRunFromMondayToSunday(): void
    {
        // October 2026: Thursday 1st to Saturday 31st
        $calendar = $this->subject->build(2026, 10, [], new \DateTimeImmutable('2026-10-22'));

        self::assertCount(5, $calendar['weeks']);
        $first = $calendar['weeks'][0]['days'];
        self::assertSame('2026-09-28', $first[0]['date']->format('Y-m-d'));
        self::assertFalse($first[0]['inMonth']);
        self::assertTrue($first[3]['inMonth']);
        self::assertSame('2026-11-01', $calendar['weeks'][4]['days'][6]['date']->format('Y-m-d'));
        self::assertSame('40', $calendar['weeks'][0]['number']);
        self::assertSame(['month' => 9, 'year' => 2026], $calendar['previous']);
        self::assertSame(['month' => 11, 'year' => 2026], $calendar['next']);
    }

    #[Test]
    public function todayIsMarked(): void
    {
        $calendar = $this->subject->build(2026, 10, [], new \DateTimeImmutable('2026-10-22'));

        $today = array_values(array_filter(array_merge(...array_column($calendar['weeks'], 'days')), static fn(array $day): bool => $day['isToday']));
        self::assertCount(1, $today);
        self::assertSame('2026-10-22', $today[0]['date']->format('Y-m-d'));
    }

    #[Test]
    public function eventsAppearOnEachOfTheirDaysInTheMonth(): void
    {
        $concert = $this->event('1', '2026-10-03T19:00:00+02:00');
        $holidays = $this->event('2', '2026-10-30T10:00:00+01:00', '2026-11-02T12:00:00+01:00');
        $sinceSeptember = $this->event('3', '2026-09-29T10:00:00+02:00', '2026-10-01T18:00:00+02:00');

        $calendar = $this->subject->build(2026, 10, [$concert, $holidays, $sinceSeptember]);
        $days = [];
        foreach (array_merge(...array_column($calendar['weeks'], 'days')) as $day) {
            $days[$day['date']->format('Y-m-d')] = array_map(static fn(Event $event): string => $event->getId(), $day['events']);
        }

        self::assertSame(['1'], $days['2026-10-03']);
        self::assertSame(['2'], $days['2026-10-30']);
        self::assertSame(['2'], $days['2026-10-31']);
        self::assertSame([], $days['2026-11-01'], 'Days of the next month stay empty');
        self::assertSame(['3'], $days['2026-10-01']);
        self::assertSame([], $days['2026-09-29']);
        self::assertSame(3, $calendar['eventCount']);
    }

    #[Test]
    public function eventsAtNightCountForTheLocalDay(): void
    {
        $calendar = $this->subject->build(2026, 10, [$this->event('1', '2026-10-09T23:30:00Z')]);

        foreach (array_merge(...array_column($calendar['weeks'], 'days')) as $day) {
            if ($day['events'] !== []) {
                self::assertSame('2026-10-10', $day['date']->format('Y-m-d'), '23:30 UTC is 01:30 in Berlin');
            }
        }
    }

    #[Test]
    public function invalidMonthsBecomeTheCurrentMonth(): void
    {
        $now = new \DateTimeImmutable('2026-10-22');

        self::assertSame([2026, 10], $this->subject->normalize(2026, 13, $now));
        self::assertSame([2026, 10], $this->subject->normalize(0, 0, $now));
        self::assertSame([2027, 1], $this->subject->normalize(2027, 1, $now));
    }

    private function event(string $id, string $startsAt, string $endsAt = ''): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setTitle('Event ' . $id);
        $event->setStartsAt($startsAt);
        $event->setEndsAt($endsAt);
        return $event;
    }
}
