<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Nordkirche Veranstaltungen',
    'description' => 'TYPO3 Extension zur Darstellung von Veranstaltungen aus der NAPI',
    'category' => 'plugin',
    'author' => 'netzleuchten GmbH',
    'author_email' => 'hallo@netzleuchten.com',
    'state' => 'stable',
    'version' => '13.0.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
            'nkc_base' => '13.0.0-13.99.99',
            'nk_google_map' => '13.0.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
