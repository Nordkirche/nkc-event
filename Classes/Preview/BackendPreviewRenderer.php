<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Preview;

use Nordkirche\Ndk\Api;
use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\Ndk\Service\NapiService;
use Nordkirche\Ndk\Service\Result;
use Nordkirche\NkcBase\MapCache\MapMarkerCache;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use Nordkirche\NkcEvent\Service\EventMapItemService;
use Nordkirche\NkcEvent\Service\EventQueryService;
use TYPO3\CMS\Backend\Preview\PreviewRendererInterface;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Preview of the nkc_event plugins in the page module
 */
class BackendPreviewRenderer implements PreviewRendererInterface
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * @var array
     */
    protected $flexformData;

    /**
     * Page of the content element
     */
    private int $pageId = 0;

    public function __construct(
        private readonly NdkApiFactory $ndkApiFactory,
        private readonly EventMapItemService $mapItemService,
        private readonly EventQueryService $queryService,
    ) {}

    public function renderPageModulePreviewHeader(GridColumnItem $item): string
    {
        $label = $this->getLanguageService()->sL('LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.' . MapMarkerCache::getPluginType($this->getRow($item)));
        return '<h3>' . htmlspecialchars($label) . '</h3>';
    }

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        $row = $this->getRow($item);
        $this->pageId = (int)($row['pid'] ?? 0);
        $flexform = GeneralUtility::xml2array((string)($row['pi_flexform'] ?? ''));
        $this->flexformData = is_array($flexform) ? $flexform : [];

        try {
            $this->api = $this->ndkApiFactory->getApi();
            return $this->renderPluginPreview(MapMarkerCache::getPluginType($row));
        } catch (\Throwable $e) {
            return '<p>' . htmlspecialchars('NAPI nicht erreichbar: ' . $e->getMessage()) . '</p>';
        }
    }

    private function renderPluginPreview(string $pluginType): string
    {
        $content = '';
        switch ($pluginType) {
            case 'nkcevent_show':
                $content .= $this->renderEventSingleView();
                break;
            case 'nkcevent_list':
            case 'nkcevent_calendar':
                $layoutKey = $this->getFieldFromFlexform('settings.flexform.searchFormTemplate', 'sTemplate');
                $content .= '<p>Layout: ' . ($layoutKey ? $layoutKey : 'Default') . '</p>';
                $content .= $this->renderEventListView();
                break;
            case 'nkcevent_map':
            case 'nkcevent_maplist':
                $content .= $this->renderMapView();
                break;
        }

        return $content;
    }

    public function renderPageModulePreviewFooter(GridColumnItem $item): string
    {
        return 'Powered by NAPI';
    }

    public function wrapPageModulePreview(string $previewHeader, string $previewContent, GridColumnItem $item): string
    {
        return $previewHeader . $previewContent;
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function renderMapView()
    {
        $content = '';

        $settings = [
            'asyncLoadingMaxItems' => 10,
            'flexform' => [
                'institutionCollection' => $this->getFieldFromFlexform('settings.flexform.institutionCollection', 'sMarker'),
                'eventCollection' => $this->getFieldFromFlexform('settings.flexform.eventCollection', 'sMarker'),
                'categories' => $this->getFieldFromFlexform('settings.flexform.categories', 'sMarker'),
                'selectCategoryOption' => $this->getFieldFromFlexform('settings.flexform.selectCategoryOption', 'sMarker'),
                'cities' => $this->getFieldFromFlexform('settings.flexform.cities', 'sMarker'),
                'zipCodes' => $this->getFieldFromFlexform('settings.flexform.zipCodes', 'sMarker'),
                'dateFrom' => $this->getFieldFromFlexform('settings.flexform.dateFrom', 'sMarker'),
                'dateTo' => $this->getFieldFromFlexform('settings.flexform.dateTo', 'sMarker'),
                'numDays' => $this->getFieldFromFlexform('settings.flexform.numDays', 'sMarker'),
            ],
        ];

        $result = $this->mapItemService->getMapItems($settings);
        $limit = $result['limitExceeded'];
        $mapItems = $result['items'];

        $content .= '<p>Marker:<br /><ul>';

        foreach ($mapItems as $record) {
            $content .= '<li>';
            $content .= htmlspecialchars((string)$record->getLabel());
            $content .= ' [' . (int)($record->getId()) . ']';
            $content .= '</li>';
        }

        $content .= '</ul></p>';

        if ($limit) {
            $content .= '... und weitere ' . ($mapItems instanceof Result ? $mapItems->getRecordCount() - 10 : '');
        }

        return $content;
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function renderEventListView()
    {
        $content = '';

        $eventRepository = $this->api->factory(EventRepository::class);

        $query = new EventQuery();

        // Set pagination parameters
        $query->setPageSize(10);

        $flexform = [
            'eventTypes' => $this->getFieldFromFlexform('settings.flexform.eventTypes', 'sDEF'),
            'institutionCollection' => $this->getFieldFromFlexform('settings.flexform.institutionCollection', 'sDEF'),
            'targetGroupCollection' => $this->getFieldFromFlexform('settings.flexform.targetGroupCollection', 'sDEF'),
            'eventLocation' => $this->getFieldFromFlexform('settings.flexform.eventLocation', 'sDEF'),
            'categories' => $this->getFieldFromFlexform('settings.flexform.categories', 'sDEF'),
            'categoryOperator' => $this->getFieldFromFlexform('settings.flexform.categoryOperator', 'sDEF'),
            'geosearch' => $this->getFieldFromFlexform('settings.flexform.geosearch', 'sDEF'),
            'latitude' => $this->getFieldFromFlexform('settings.flexform.latitude', 'sDEF'),
            'longitude' => $this->getFieldFromFlexform('settings.flexform.longitude', 'sDEF'),
            'radius' => $this->getFieldFromFlexform('settings.flexform.radius', 'sDEF'),
            'dateFrom' => $this->getFieldFromFlexform('settings.flexform.dateFrom', 'sDEF'),
            'dateTo' => $this->getFieldFromFlexform('settings.flexform.dateTo', 'sDEF'),
            'numDays' => $this->getFieldFromFlexform('settings.flexform.numDays', 'sDEF'),
            'includeChildInstitutions' => $this->getFieldFromFlexform('settings.flexform.includeChildInstitutions', 'sDEF'),
            'includeSubCategories' => $this->getFieldFromFlexform('settings.flexform.includeSubCategories', 'sDEF'),
            'cities' => $this->getFieldFromFlexform('settings.flexform.cities', 'sDEF'),
            'zipCodes' => $this->getFieldFromFlexform('settings.flexform.zipCodes', 'sDEF'),
            'restrictToSiteInstitution' => $this->getFieldFromFlexform('settings.flexform.restrictToSiteInstitution', 'sDEF'),
            'churchAssociation' => $this->getFieldFromFlexform('settings.flexform.churchAssociation', 'sDEF'),
        ];

        // Institution of the site: from the site settings. Set in TypoScript only, it is unknown here.
        $settings = ['siteInstitutions' => $this->getSiteInstitutions()];
        $needsSite = !empty($flexform['restrictToSiteInstitution'])
            || $flexform['churchAssociation'] === EventQueryService::CHURCH_ASSOCIATION_OF_SITE;
        if ($needsSite && $this->queryService->getSiteInstitutions($settings) === []) {
            return '<p><em>' . htmlspecialchars(
                'Die Veranstaltungen hängen von der Institution der Site ab (TypoScript plugin.tx_nkcevent.settings.siteInstitutions). '
                . 'Sie ist nur im Frontend bekannt, deshalb gibt es hier keine Vorschau.'
            ) . '</em></p>';
        }

        $this->queryService->applyListFilters($query, $flexform, $settings);
        // Same as the frontend list: no past events
        $query->setTimeFromStart(new \DateTime('today'));

        // Get Events
        $events = $eventRepository->get($query);

        if ($events->getRecordCount() === 0) {
            return 'Keine Treffer!';
        }

        $content .= '<p>Vorschau:<br /><ul>';
        foreach ($events as $event) {
            $content .= '<li>' . htmlspecialchars((string)$event->getLabel()) . ' [' . (int)$event->getId() . ']</li>';
        }
        $content .= '</ul></p>';
        if ($events->getPageCount() > 1) {
            $content .= '... und ' . ($events->getRecordCount() - 10) . ' weitere Veranstaltungen';
        }

        return $content;
    }

    /**
     * @return string
     */
    private function renderEventSingleView()
    {
        $content = '';
        $eventResource = $this->getFieldFromFlexform('settings.flexform.singleEvent', 'sDEF');
        if ($eventResource) {
            $content .= '<p>Zeige ausgewähltes Event: ';
            $napiService = $this->api->factory(NapiService::class);
            $event = $napiService->resolveUrl($eventResource);
            if ($event instanceof Event) {
                $content .= htmlspecialchars((string)$event->getTitle());
                $content .= ' [' . (int)($event->getId()) . ']';
            } else {
                $content .= '[nicht gefunden]';
            }
            $content .= '</p>';
        } else {
            $content .= '<p>Zeige Veranstaltung via URL Parameter</p>';
        }
        return $content;
    }

    /**
     * Get field value from flexform configuration,
     * including checks if flexform configuration is available
     *
     * @param string $key name of the key
     * @param string $sheet name of the sheet
     * @return string|null if nothing found, value if found
     */
    protected function getFieldFromFlexform($key, $sheet = 'sDEF')
    {
        $flexform = $this->flexformData;

        if (isset($flexform['data'])) {
            $flexform = $flexform['data'];
            if (is_array($flexform) && is_array($flexform[$sheet] ?? null) && is_array($flexform[$sheet]['lDEF'] ?? null)
                && isset($flexform[$sheet]['lDEF'][$key]) && is_array($flexform[$sheet]['lDEF'][$key]) && isset($flexform[$sheet]['lDEF'][$key]['vDEF'])
            ) {
                return $flexform[$sheet]['lDEF'][$key]['vDEF'];
            }
        }

        return null;
    }

    /**
     * plugin.tx_nkcevent.settings.siteInstitutions of the site settings
     */
    private function getSiteInstitutions(): string
    {
        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($this->pageId);
            return (string)$site->getSettings()->get('plugin.tx_nkcevent.settings.siteInstitutions', '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Record of the content element (TYPO3 v14: getRow(), v13: getRecord())
     */
    private function getRow(GridColumnItem $item): array
    {
        return method_exists($item, 'getRow') ? $item->getRow() : (array)$item->getRecord();
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
