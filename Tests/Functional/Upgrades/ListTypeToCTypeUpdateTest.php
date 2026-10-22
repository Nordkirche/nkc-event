<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Functional\Upgrades;

use Nordkirche\NkcEvent\Upgrades\ListTypeToCTypeUpdate;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ListTypeToCTypeUpdateTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['nordkirche/nkc-base', 'nordkirche/nk-google-map', 'nordkirche/nkc-event'];

    #[Test]
    public function wizardIsRegistered(): void
    {
        $registry = $this->get(class_exists(\TYPO3\CMS\Core\Upgrades\UpgradeWizardRegistry::class)
            ? \TYPO3\CMS\Core\Upgrades\UpgradeWizardRegistry::class
            : \TYPO3\CMS\Install\Updates\UpgradeWizardRegistry::class);

        self::assertInstanceOf(ListTypeToCTypeUpdate::class, $registry->getUpgradeWizard(ListTypeToCTypeUpdate::IDENTIFIER));
    }

    #[Test]
    public function everyMigratedPluginIsARegisteredContentType(): void
    {
        $contentTypes = array_column($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'], 'value');
        $mapping = (fn(): array => $this->getListTypeToCTypeMapping())->call($this->get(ListTypeToCTypeUpdate::class));

        self::assertSame([], array_diff($mapping, $contentTypes));
        self::assertCount(6, $mapping);
    }

    #[Test]
    public function withoutListTypeColumnNoUpdateIsNecessary(): void
    {
        if ($this->hasListTypeColumn()) {
            self::markTestSkipped('TYPO3 v13 still has tt_content.list_type');
        }
        self::assertFalse($this->get(ListTypeToCTypeUpdate::class)->updateNecessary());
    }

    #[Test]
    public function pluginsAndPermissionsAreMigrated(): void
    {
        if (!$this->hasListTypeColumn()) {
            self::markTestSkipped('The migration needs tt_content.list_type (TYPO3 v13)');
        }
        $connectionPool = $this->get(ConnectionPool::class);
        $content = $connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 1, 'pid' => 1, 'CType' => 'list', 'list_type' => 'nkcevent_list']);
        $content->insert('tt_content', ['uid' => 2, 'pid' => 1, 'CType' => 'list', 'list_type' => 'news_pi1']);
        $content->insert('tt_content', ['uid' => 3, 'pid' => 1, 'CType' => 'text', 'list_type' => '']);
        $connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Redaktion',
            'explicit_allowdeny' => 'tt_content:CType:text,tt_content:list_type:nkcevent_list,tt_content:list_type:news_pi1',
        ]);
        $wizard = $this->get(ListTypeToCTypeUpdate::class);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertFalse($wizard->updateNecessary());

        $rows = $content->select(['uid', 'CType', 'list_type'], 'tt_content', [], [], ['uid' => 'ASC'])->fetchAllAssociative();
        self::assertSame(['nkcevent_list', ''], [$rows[0]['CType'], $rows[0]['list_type']]);
        self::assertSame(['list', 'news_pi1'], [$rows[1]['CType'], $rows[1]['list_type']], 'Other plugins stay untouched');
        self::assertSame('text', $rows[2]['CType']);

        $permissions = explode(',', (string)$connectionPool->getConnectionForTable('be_groups')
            ->select(['explicit_allowdeny'], 'be_groups', ['uid' => 1])->fetchOne());
        self::assertContains('tt_content:CType:nkcevent_list', $permissions);
        self::assertNotContains('tt_content:list_type:nkcevent_list', $permissions);
        self::assertContains('tt_content:list_type:news_pi1', $permissions);
        self::assertContains('tt_content:CType:text', $permissions);
    }

    private function hasListTypeColumn(): bool
    {
        $schemaManager = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->createSchemaManager();
        return isset($schemaManager->listTableColumns('tt_content')['list_type']);
    }
}
