<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\NkcEvent\Service\DisplayedEvents;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DisplayedEventsTest extends UnitTestCase
{
    #[Test]
    public function eventsOfEarlierListsAreLeftOut(): void
    {
        $subject = new DisplayedEvents();
        $subject->add($this->createResult(['1', '2']));

        $remaining = $subject->filter($this->createResult(['2', '3', '1', '4', '5']), 2);

        self::assertSame(['3', '4'], $this->ids($remaining), 'At most the limit');
        self::assertSame(3, $remaining->getRecordCount());
        self::assertSame(2, $subject->count());
    }

    #[Test]
    public function withoutLimitAllOthersRemain(): void
    {
        $subject = new DisplayedEvents();
        $subject->add($this->createResult(['1']));

        self::assertSame(['2', '3'], $this->ids($subject->filter($this->createResult(['1', '2', '3']))));
    }

    private function createResult(array $ids): Result
    {
        $storage = new \SplObjectStorage();
        foreach ($ids as $id) {
            $event = new Event();
            $event->setId($id);
            $storage->offsetSet($event);
        }
        return new Result($storage, 1, count($ids), []);
    }

    private function ids(Result $result): array
    {
        $ids = [];
        foreach ($result as $event) {
            $ids[] = $event->getId();
        }
        return $ids;
    }
}
