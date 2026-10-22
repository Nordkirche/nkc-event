<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'nkcevent-plugin' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nkc_event/Resources/Public/Icons/user_plugin_main.svg',
    ],
    'nkcevent-plugin-map' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nkc_base/Resources/Public/Icons/icon_map.svg',
    ],
];
