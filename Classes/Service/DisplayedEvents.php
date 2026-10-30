<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Service\Result;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Events already shown on the current page, so that further lists can leave them out
 * ("Bereits angezeigte Veranstaltungen nicht darstellen"). The plugins of a page are
 * rendered one after the other in the same request, also when the page is cached.
 */
class DisplayedEvents implements SingletonInterface
{
    /**
     * @var array<string, true>
     */
    private array $ids = [];

    /**
     * @param iterable<Event> $events
     */
    public function add(iterable $events): void
    {
        foreach ($events as $event) {
            if ($event instanceof Event) {
                $this->ids[(string)$event->getId()] = true;
            }
        }
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /**
     * The events not shown yet, at most $limit (0: all)
     */
    public function filter(Result $events, int $limit = 0): Result
    {
        $remaining = new \SplObjectStorage();
        $removed = 0;
        foreach ($events as $event) {
            if ($event instanceof Event && isset($this->ids[(string)$event->getId()])) {
                $removed++;
                continue;
            }
            if ($limit > 0 && $remaining->count() >= $limit) {
                break;
            }
            $remaining->offsetSet($event);
        }
        return new Result($remaining, $events->getPageCount(), max(0, $events->getRecordCount() - $removed), $events->getFacets());
    }

    public function reset(): void
    {
        $this->ids = [];
    }
}
