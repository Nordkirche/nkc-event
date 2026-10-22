<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Controller;

use Nordkirche\NkcBase\Controller\BaseController;
use Nordkirche\NkcBase\MapCache\MapMarkerCache;
use Nordkirche\NkcEvent\Service\EventMapItemService;
use Psr\Http\Message\ResponseInterface;

/**
 * Plugins "Veranstaltungen: Karte" (show) and "Karte und Liste" (list)
 */
class MapController extends BaseController
{
    public function __construct(
        private readonly EventMapItemService $mapItemService,
        private readonly MapMarkerCache $mapMarkerCache,
    ) {}

    public function showAction(): ResponseInterface
    {
        $this->createView(1, 'map');
        return $this->htmlResponse();
    }

    public function listAction(int $currentPage = 1): ResponseInterface
    {
        $this->createView($currentPage, 'maplist');
        return $this->htmlResponse();
    }

    /**
     * All markers as JSON, from the nkc_base map marker cache
     */
    public function dataAction(): ResponseInterface
    {
        $entry = $this->mapMarkerCache->get($this->getContentRow(), $this->settings, $this->request->getAttribute('site'));
        return $this->jsonResponse((string)json_encode($entry + ['complete' => true]));
    }

    /**
     * Markers page by page (all at once, if the cache holds them already)
     */
    public function paginatedDataAction(int $page = 1): ResponseInterface
    {
        $row = $this->getContentRow();
        if ($this->mapMarkerCache->has($row)) {
            $entry = $page === 1 ? $this->mapMarkerCache->get($row, $this->settings, $this->request->getAttribute('site')) : ['data' => []];
            return $this->jsonResponse((string)json_encode(['data' => $entry['data'], 'complete' => true]));
        }

        $result = $this->mapItemService->getMapItems($this->settings, false, $page);
        $markers = $this->mapItemService->createMarkers($result['items'], $this->settings);
        return $this->jsonResponse((string)json_encode([
            'data' => $markers,
            'complete' => $markers === [] || $page >= $result['pageCount'],
        ]));
    }

    private function createView(int $currentPage, string $pluginName): void
    {
        $result = $this->mapItemService->getMapItems($this->settings, false, $currentPage);
        $row = $this->getContentRow();

        if ($result['limitExceeded']) {
            // Too many events: the map loads the markers asynchronously
            $action = empty($this->settings['flexform']['stream']) ? 'data' : 'paginatedData';
            $uri = $this->uriBuilder->reset()
                ->setTargetPageUid($this->getCurrentPageId())
                ->setTargetPageType((int)($this->settings['ajaxTypeNum'] ?? 0))
                ->setArguments(['tx_nkcevent_' . $pluginName => ['action' => $action], 'uid' => $row['uid'] ?? 0])
                ->build();
            $this->view->assign($action === 'data' ? 'requestUri' : 'streamUri', $uri);
        } else {
            $this->view->assign('mapMarkers', $this->mapItemService->createMarkers($result['items'], $this->settings));
        }

        $this->view->assignMultiple([
            'events' => $result['items'],
            'content' => $row,
            'currentPage' => $currentPage,
            'pageCount' => $result['pageCount'],
            'nextPage' => $currentPage < $result['pageCount'] ? $currentPage + 1 : false,
        ]);

        if ((int)($this->settings['flexform']['showFilter'] ?? 0) === 1) {
            $this->view->assign('facets', $this->mapItemService->resolveFacets($result['facets'], $this->settings));
        }
    }

    private function getContentRow(): array
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        return is_object($contentObject) ? (array)$contentObject->data : [];
    }
}
