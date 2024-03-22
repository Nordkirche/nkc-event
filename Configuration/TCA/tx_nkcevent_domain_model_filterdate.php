<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xlf:tx_nkcevent_domain_model_filterdate',
        'label' => 'be_name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'searchFields' => 'be_name,name',
        'default_sortby' => 'ORDER BY date_from',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'iconfile' => 'EXT:nkc_event/Resources/Public/Icons/tx_nkcevent_domain_model_filterdate.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'hidden, name, date_from, date_to, be_name,--div--;LLL:EXT:core/Resources/Private/Language/locallang_ttc.xlf:tabs.access,starttime, endtime'],
    ],
    'palettes' => [
        '1' => ['showitem' => ''],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_ttc.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
            ],
        ],
        'starttime' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_ttc.xlf:LGL.starttime',
            'config' => [
                'type' => 'input',
                'size' => 13,
                'eval' => 'datetime',
                'checkbox' => 0,
                'default' => 0,
                'range' => [
                    'lower' => mktime(0, 0, 0, date('m'), date('d'), date('Y')),
                ],
                'renderType' => 'inputDateTime',
                ['behaviour' => ['allowLanguageSynchronization' => true]],
            ],
        ],
        'endtime' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_ttc.xlf:LGL.endtime',
            'config' => [
                'type' => 'input',
                'size' => 13,
                'eval' => 'datetime',
                'checkbox' => 0,
                'default' => 0,
                'range' => [
                    'lower' => mktime(0, 0, 0, date('m'), date('d'), date('Y')),
                ],
                'renderType' => 'inputDateTime',
                ['behaviour' => ['allowLanguageSynchronization' => true]],
            ],
        ],
        'name' => [
            'exclude' => 0,
            'label' => 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xml:tx_nkcevent_domain_model_filterdate.name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim,required',
            ],
        ],
        'be_name' => [
            'exclude' => 0,
            'label' => 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xml:tx_nkcevent_domain_model_filterdate.be_name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim,required',
            ],
        ],
        'date_from' => [
            'exclude' => 0,
            'label' => 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xml:tx_nkcevent_domain_model_filterdate.date_from',
            'config' => [
                'type' => 'input',
                'size' => 13,
                'eval' => 'date',
                'checkbox' => 0,
                'default' => 0,
                'renderType' => 'inputDateTime',
            ],
        ],
        'date_to' => [
            'exclude' => 0,
            'label' => 'LLL:EXT:nkc_event/Resources/Private/Language/locallang_db.xml:tx_nkcevent_domain_model_filterdate.date_to',
            'config' => [
                'type' => 'input',
                'size' => 13,
                'eval' => 'date',
                'checkbox' => 0,
                'default' => 0,
                'renderType' => 'inputDateTime',
            ],
        ],
    ],
];
