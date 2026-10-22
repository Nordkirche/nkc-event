<?php

declare(strict_types=1);

use Nordkirche\NkcEvent\Upgrades\ListTypeToCTypeUpdate;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    // Upgrade wizard: base class in EXT:core (v14) or EXT:install (v13)
    if (class_exists(\TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate::class)
        || class_exists(\TYPO3\CMS\Install\Updates\AbstractListTypeToCTypeUpdate::class)
    ) {
        $configurator->services()
            ->set(ListTypeToCTypeUpdate::class)
            ->autowire()
            ->public()
            ->tag('install.upgradewizard', ['identifier' => ListTypeToCTypeUpdate::IDENTIFIER]);
    }
};
