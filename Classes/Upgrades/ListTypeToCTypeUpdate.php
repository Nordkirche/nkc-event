<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Upgrades;

// TYPO3 v14 moved the base class from EXT:install to EXT:core
if (!class_exists(AbstractListTypeToCTypeUpdateCompat::class, false)) {
    class_alias(
        class_exists(\TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate::class)
            ? \TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate::class
            : \TYPO3\CMS\Install\Updates\AbstractListTypeToCTypeUpdate::class,
        AbstractListTypeToCTypeUpdateCompat::class
    );
}

/**
 * Migrates the plugins of nkc_event from "list_type" to their own content
 * types (CType), including the permissions of backend user groups.
 * Registered in Configuration/Services.php.
 */
final class ListTypeToCTypeUpdate extends AbstractListTypeToCTypeUpdateCompat
{
    public const IDENTIFIER = 'nkcEventListTypeToCType';

    private const PLUGINS = [
        'nkcevent_show',
        'nkcevent_list',
        'nkcevent_searchform',
        'nkcevent_redirect',
        'nkcevent_map',
        'nkcevent_maplist',
    ];

    protected function getListTypeToCTypeMapping(): array
    {
        return array_combine(self::PLUGINS, self::PLUGINS);
    }

    public function getTitle(): string
    {
        return 'nkc_event: Plugins auf eigene Inhaltstypen umstellen';
    }

    public function getDescription(): string
    {
        return 'Migriert die Plugins von nkc_event von "Allgemeines Plugin" (list_type) auf eigene Inhaltstypen (CType), inklusive der Berechtigungen der Backend-Benutzergruppen.';
    }
}
