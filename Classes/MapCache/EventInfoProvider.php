<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\MapCache;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\NkcBase\MapCache\MarkerInfoProviderInterface;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcBase\Service\TemplateRenderer;
use Nordkirche\NkcEvent\Service\EventMarkerService;
use Nordkirche\NkcEvent\Service\EventSettings;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Info windows of event markers ("e:123")
 */
final readonly class EventInfoProvider implements MarkerInfoProviderInterface
{
    public function __construct(
        private NdkApiFactory $ndkApiFactory,
        private TemplateRenderer $renderer,
        private EventSettings $eventSettings,
        private EventMarkerService $markerService,
    ) {}

    public function getObjectType(): string
    {
        return 'e';
    }

    public function renderInfo(array $ids, ServerRequestInterface $request, Site $site): string
    {
        $query = new EventQuery();
        $query->setInclude([Event::RELATION_ADDRESS, Event::RELATION_CHIEF_ORGANIZER, Event::RELATION_CATEGORY]);
        $query->setEvents($ids);
        $query->setPageSize(min(count($ids), 100));

        $settings = $this->eventSettings->forMapInfo($site);
        $paths = $this->eventSettings->getViewPaths($site);

        $html = '';
        foreach ($this->ndkApiFactory->getApi()->factory(EventRepository::class)->get($query) as $event) {
            $html .= $this->renderer->render('Event/AsyncMapInfo', [
                'event' => $event,
                'settings' => $settings,
                'addConfig' => [
                    'digitalEvent' => $this->markerService->isDigital($event, $settings),
                    'hybridEvent' => $this->markerService->isHybrid($event, $settings),
                ],
            ], $paths, $request);
        }
        return $html;
    }
}
