<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die('Access denied.');

call_user_func(
    function () {
        ExtensionUtility::registerPlugin(
            'NkcEvent',
            'Show',
            'Veranstaltung: Detailansicht'
        );

        ExtensionUtility::registerPlugin(
            'NkcEvent',
            'List',
            'Veranstaltungen: Liste / Suchergebnis'
        );

        ExtensionUtility::registerPlugin(
            'NkcEvent',
            'SearchForm',
            'Veranstaltungen: Suchmaske'
        );

        ExtensionUtility::registerPlugin(
            'NkcEvent',
            'Redirect',
            'Veranstaltungen: Umleitung auf sprechende Url'
        );

        ExtensionUtility::registerPlugin(
            'NkcEvent',
            'Map',
            'Veranstaltungen: Karte'
        );

        ExtensionUtility::registerPlugin(
            'NkcEvent',
            'MapList',
            'Veranstaltungen: Karte und Liste'
        );

        ExtensionManagementUtility::addStaticFile('nkc_event', 'Configuration/TypoScript', 'Nordkirche Event Client');
    }
);
