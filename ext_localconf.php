<?php

use Nordkirche\NkcEvent\Controller\EventController;
use Nordkirche\NkcEvent\Controller\MapController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die();

$plugins = [
    // plugin name => [actions, non-cacheable actions]
    'Show' => [[EventController::class => 'show,export'], [EventController::class => 'export']],
    'List' => [[EventController::class => 'list,search,data,paginatedData'], [EventController::class => 'search,paginatedData']],
    'SearchForm' => [[EventController::class => 'searchForm'], [EventController::class => 'searchForm']],
    'Redirect' => [[EventController::class => 'redirect'], [EventController::class => 'redirect']],
    'Map' => [[MapController::class => 'show,data,paginatedData'], [MapController::class => 'paginatedData']],
    'MapList' => [[MapController::class => 'list,data,paginatedData'], [MapController::class => 'paginatedData']],
];
foreach ($plugins as $pluginName => [$actions, $nonCacheableActions]) {
    ExtensionUtility::configurePlugin('NkcEvent', $pluginName, $actions, $nonCacheableActions, ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT);
}

// Short links of the redirect plugins (?nkci=123): no cHash
foreach (['nkce'] as $parameter) {
    $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = $parameter;
}

// Parameters added by the map JavaScript (paginated loading of markers)
foreach (['tx_nkcevent_list', 'tx_nkcevent_map', 'tx_nkcevent_maplist'] as $namespace) {
    $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = $namespace . '[page]';
    $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = $namespace . '[requestId]';
}
