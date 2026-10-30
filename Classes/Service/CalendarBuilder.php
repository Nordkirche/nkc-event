<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;

/**
 * Month sheet of the calendar plugin: weeks from Monday to Sunday, the events on each day.
 * Events over several days appear on each of their days in the month.
 */
class CalendarBuilder
{
    /**
     * @param iterable<Event> $events
     * @return array{
     *     month: int, year: int, start: \DateTimeImmutable, previous: array{month: int, year: int}, next: array{month: int, year: int},
     *     weeks: list<array{number: string, days: list<array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, events: list<Event>}>}>,
     *     eventCount: int
     * }
     */
    public function build(int $year, int $month, iterable $events, ?\DateTimeImmutable $today = null): array
    {
        $timeZone = new \DateTimeZone(date_default_timezone_get());
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timeZone);
        $end = $start->modify('last day of this month');
        $today = ($today ?? new \DateTimeImmutable('today', $timeZone))->format('Y-m-d');

        $eventsOfDay = [];
        $eventCount = 0;
        foreach ($events as $event) {
            $first = $this->getDay($event, 'getStartsAt');
            if ($first === null) {
                continue;
            }
            $last = $this->getDay($event, 'getEndsAt') ?? $first;
            if ($last < $first) {
                $last = $first;
            }
            // Only the days in this month
            $day = max($first, $start);
            $last = min($last, $end);
            $counted = false;
            for (; $day <= $last; $day = $day->modify('+1 day')) {
                $eventsOfDay[$day->format('Y-m-d')][] = $event;
                $counted = true;
            }
            $eventCount += $counted ? 1 : 0;
        }

        $weeks = [];
        $day = $start->modify('-' . ((int)$start->format('N') - 1) . ' days');
        $lastDay = $end->modify('+' . (7 - (int)$end->format('N')) . ' days');
        while ($day <= $lastDay) {
            $week = ['number' => $day->format('W'), 'days' => []];
            for ($i = 0; $i < 7; $i++) {
                $key = $day->format('Y-m-d');
                $week['days'][] = [
                    'date' => $day,
                    'inMonth' => (int)$day->format('n') === $month,
                    'isToday' => $key === $today,
                    'events' => (int)$day->format('n') === $month ? ($eventsOfDay[$key] ?? []) : [],
                ];
                $day = $day->modify('+1 day');
            }
            $weeks[] = $week;
        }

        $previous = $start->modify('-1 month');
        $next = $start->modify('+1 month');
        return [
            'month' => $month,
            'year' => $year,
            'start' => $start,
            'previous' => ['month' => (int)$previous->format('n'), 'year' => (int)$previous->format('Y')],
            'next' => ['month' => (int)$next->format('n'), 'year' => (int)$next->format('Y')],
            'weeks' => $weeks,
            'eventCount' => $eventCount,
        ];
    }

    /**
     * Valid month and year, the current month otherwise
     *
     * @return array{0: int, 1: int} [year, month]
     */
    public function normalize(int $year, int $month, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        if ($month < 1 || $month > 12 || $year < 1970 || $year > 2200) {
            return [(int)$now->format('Y'), (int)$now->format('n')];
        }
        return [$year, $month];
    }

    /**
     * Day of the start or end, in the local time zone. The NDK has no value for events without end.
     */
    private function getDay(Event $event, string $getter): ?\DateTimeImmutable
    {
        try {
            $date = $event->$getter();
        } catch (\Error) {
            return null;
        }
        if (!$date instanceof \DateTimeInterface) {
            return null;
        }
        $local = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $local->setTime(0, 0);
    }
}
