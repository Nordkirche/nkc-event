<?php

use Nordkirche\NkcEvent\Controller\EventController;
use Nordkirche\NkcEvent\Controller\MapController;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die('Access denied.');

call_user_func(
    function () {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_nkcevent_map[page]';
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_nkcevent_main[page]';

        ExtensionUtility::configurePlugin(
            'NkcEvent',
            'Show',
            [
                EventController::class => 'show, export',
            ],
            // non-cacheable actions
            [
                EventController::class => 'export',
            ]
        );

        ExtensionUtility::configurePlugin(
            'NkcEvent',
            'List',
            [
                EventController::class => 'list, search, data, paginatedData',
            ],
            // non-cacheable actions
            [
                EventController::class => 'search, paginatedData',
            ]
        );

        ExtensionUtility::configurePlugin(
            'NkcEvent',
            'SearchForm',
            [
                EventController::class => 'searchForm',
            ],
            // non-cacheable actions
            [
                EventController::class => 'searchForm',
            ]
        );

        ExtensionUtility::configurePlugin(
            'NkcEvent',
            'Redirect',
            [
                EventController::class => 'redirect',
            ],
            // non-cacheable actions
            [
                EventController::class => 'redirect',
            ]
        );

        ExtensionUtility::configurePlugin(
            'NkcEvent',
            'Map',
            [
                MapController::class => 'show,data,paginatedData',
            ],
            // non-cacheable actions
            [
                MapController::class => 'paginatedData',
            ]
        );

        ExtensionUtility::configurePlugin(
            'NkcEvent',
            'MapList',
            [
                MapController::class => 'list,data,paginatedData',
            ],
            // non-cacheable actions
            [
                MapController::class => 'paginatedData',
            ]
        );

        // wizards
        ExtensionManagementUtility::addPageTSConfig(
            'mod {
                        wizards.newContentElement.wizardItems.nordkirche_events {
                            header = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.header
                            elements {
                               event_list {
                                    iconIdentifier = content-plugin
                                    title = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_list
                                    description = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_list.description
                                    tt_content_defValues {
                                        CType = list
                                        list_type = nkcevent_list
                                    }
                                }
                               event_show {
                                    iconIdentifier = content-plugin
                                    title = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_show
                                    description = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_show.description
                                    tt_content_defValues {
                                        CType = list
                                        list_type = nkcevent_show
                                    }
                                }
                               event_searchform {
                                    iconIdentifier = content-plugin
                                    title = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_searchform
                                    description = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_searchform.description
                                    tt_content_defValues {
                                        CType = list
                                        list_type = nkcevent_searchform
                                    }
                                }
                                event_map {
                                    iconIdentifier = content-plugin
                                    title = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_map
                                    description = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_map.description
                                    tt_content_defValues {
                                        CType = list
                                        list_type = nkcevent_map
                                    }
                                }
                                event_maplist {
                                    iconIdentifier = content-plugin
                                    title = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_maplist
                                    description = LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:wizard.nkcevent_maplist.description
                                    tt_content_defValues {
                                        CType = list
                                        list_type = nkcevent_maplist
                                    }
                                }
                            }
                            show = *
                        }
                   }'
        );

        $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'] = 0;

        // Register Upgrade wizards
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['txNkcEventPluginUpdater'] = \Nordkirche\NkcEvent\Updates\PluginUpdater::class;
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['txNkcEventPluginPermissionUpdater'] = \Nordkirche\NkcEvent\Updates\PluginPermissionUpdater::class;
    }
);
