<?php

use Nordkirche\NkcEvent\Preview\BackendPreviewRenderer;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die();

$ll = 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:';
$isV14 = (new Typo3Version())->getMajorVersion() >= 14;

ExtensionManagementUtility::addTcaSelectItemGroup('tt_content', 'CType', 'nordkirche', 'Nordkirche (NAPI)', 'after:plugins');

$plugins = [
    // plugin name => flexform
    'List' => 'list',
    'Calendar' => 'calendar',
    'Show' => 'show',
    'SearchForm' => 'searchform',
    'Redirect' => '',
    'Map' => 'map',
    'MapList' => 'maplist',
];

foreach ($plugins as $pluginName => $flexForm) {
    $signature = 'nkcevent_' . strtolower($pluginName);
    $flexFormFile = $flexForm !== '' ? 'FILE:EXT:nkc_event/Configuration/FlexForms/flexform_' . $flexForm . '.xml' : '';
    $icon = in_array($pluginName, ['Map', 'MapList'], true) ? 'nkcevent-plugin-map' : 'nkcevent-plugin';
    $arguments = ['NkcEvent', $pluginName, $ll . 'wizard.' . $signature, $icon, 'nordkirche', $ll . 'wizard.' . $signature . '.description'];

    if ($isV14) {
        // TYPO3 v14: flexform as part of the plugin registration
        ExtensionUtility::registerPlugin(...[...$arguments, $flexFormFile]);
    } else {
        ExtensionUtility::registerPlugin(...$arguments);
        if ($flexFormFile !== '') {
            ExtensionManagementUtility::addPiFlexFormValue('*', $flexFormFile, $signature);
            ExtensionManagementUtility::addToAllTCAtypes('tt_content', '--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.plugin, pi_flexform', $signature, 'after:palette:headers');
        }
    }

    if ($flexForm !== '') {
        $GLOBALS['TCA']['tt_content']['types'][$signature]['previewRenderer'] = BackendPreviewRenderer::class;
    }
}
