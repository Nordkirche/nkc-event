<?php

use TYPO3\CMS\Core\Information\Typo3Version;

defined('TYPO3') || die();

$ll = 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:';

$tca = [
    'ctrl' => [
        'title' => $ll . 'tx_nkcevent_domain_model_filterdate',
        'label' => 'be_name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'date_from',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'iconfile' => 'EXT:nkc_event/Resources/Public/Icons/tx_nkcevent_domain_model_filterdate.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'hidden, name, date_from, date_to, be_name, --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access, starttime, endtime'],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'starttime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'endtime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'range' => [
                    'upper' => mktime(0, 0, 0, 1, 1, 2038),
                ],
            ],
        ],
        'name' => [
            'label' => $ll . 'tx_nkcevent_domain_model_filterdate.name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'be_name' => [
            'label' => $ll . 'tx_nkcevent_domain_model_filterdate.be_name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'date_from' => [
            'label' => $ll . 'tx_nkcevent_domain_model_filterdate.date_from',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'default' => 0,
            ],
        ],
        'date_to' => [
            'label' => $ll . 'tx_nkcevent_domain_model_filterdate.date_to',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'default' => 0,
            ],
        ],
    ],
];

// TYPO3 v14 derives searchable fields from the column types
if ((new Typo3Version())->getMajorVersion() < 14) {
    $tca['ctrl']['searchFields'] = 'be_name,name';
}

return $tca;
