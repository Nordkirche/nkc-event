<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Functional\Service;

use Nordkirche\Ndk\Domain\Model\Address;
use Nordkirche\Ndk\Domain\Model\Category;
use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\NkcEvent\Service\EventMarkerService;
use Nordkirche\NkcEvent\Service\EventSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional, because the icons are public resources of nkc_base
 */
final class EventMarkerServiceTest extends FunctionalTestCase
{
    private const SETTINGS = [
        'digitalEventCategoryId' => '606,647',
        'hybridEventCategoryId' => '646',
        'mapping' => ['eventIcon' => EventSettings::EVENT_ICON_MAPPING],
    ];

    protected array $testExtensionsToLoad = [
        'nordkirche/nkc-base',
        'nordkirche/nk-google-map',
        'nordkirche/nkc-event',
    ];

    public static function eventTypeDataProvider(): \Generator
    {
        yield 'church service' => ['church_service', [], 'event-church_service', 'event_gottesdienst.svg'];
        yield 'digital (first category id)' => ['church_service', ['606'], 'digital-event-church_service', 'event_digital-gottesdienst.svg'];
        yield 'digital (second category id)' => ['music', ['1', '647'], 'digital-event-music', 'event_digital-konzerte.svg'];
        yield 'hybrid wins over digital' => ['edu', ['606', '646'], 'hybrid-event-edu', 'event_hybrid-bildung.svg'];
        yield 'no event type' => ['', [], 'event-event', 'event_gemeindeleben.svg'];
        yield 'base is an alias of event' => ['base', [], 'event-event', 'event_gemeindeleben.svg'];
        yield 'unknown event type' => ['excursion', [], 'event-excursion', 'event_gemeindeleben.svg'];
    }

    /**
     * @param string[] $categoryIds
     */
    #[Test]
    #[DataProvider('eventTypeDataProvider')]
    public function markerTypeAndIconDependOnTypeAndCategories(string $eventType, array $categoryIds, string $expectedType, string $expectedIcon): void
    {
        $marker = (new EventMarkerService())->createMarker($this->createEvent($eventType, $categoryIds), self::SETTINGS);

        self::assertSame('e', $marker['object'] ?? null);
        self::assertSame('1467188', $marker['id']);
        self::assertSame('Satt & Selig', $marker['title']);
        self::assertSame([53.615088, 9.890033], [$marker['lat'], $marker['lon']]);
        self::assertSame('', $marker['info'], 'Info windows are loaded asynchronously');
        self::assertSame($expectedType, $marker['type']);
        self::assertStringContainsString('MapIcons/' . $expectedIcon, $marker['icon']);
    }

    #[Test]
    public function eventsWithoutCoordinatesHaveNoMarker(): void
    {
        $event = $this->createEvent('music', []);
        $event->setAddress(new Address());

        self::assertNull((new EventMarkerService())->createMarker($event, self::SETTINGS));
    }

    /**
     * @param string[] $categoryIds
     */
    private function createEvent(string $eventType, array $categoryIds): Event
    {
        $categories = new \SplObjectStorage();
        foreach ($categoryIds as $id) {
            $category = new Category();
            $category->setId($id);
            $categories->attach($category);
        }
        $address = new Address();
        $address->setLatitude(53.615088);
        $address->setLongitude(9.890033);

        $event = new Event();
        $event->setId('1467188');
        $event->setTitle('Satt & Selig');
        $event->setEventType($eventType);
        $event->setAddress($address);
        $event->setCategories(new Result($categories, 1, count($categoryIds)));
        return $event;
    }
}
