<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die('Access denied.');

call_user_func(
    function () {
        foreach (['show', 'list', 'searchform', 'map', 'maplist'] as $action) {
            $pluginSignature = 'nkcevent_' . $action;
            $filename = sprintf('FILE:EXT:nkc_event/Configuration/FlexForms/flexform_%s.xml', $action);
            $GLOBALS['TCA']['tt_content']['types']['list']['subtypes_addlist'][$pluginSignature] = 'pi_flexform';
            ExtensionManagementUtility::addPiFlexFormValue(
                $pluginSignature,
                $filename
            );
            $GLOBALS['TCA']['tt_content']['types']['list']['previewRenderer'][$pluginSignature]
                = Nordkirche\NkcEvent\Preview\BackendPreviewRenderer::class;
        }
    }
);
