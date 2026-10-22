<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Structure of the flexform files, checked on the raw XML: TYPO3 would migrate
 * some mistakes on the fly (with a deprecation) or silently drop data.
 */
final class FlexFormTest extends UnitTestCase
{
    public static function flexFormDataProvider(): \Generator
    {
        foreach (glob(__DIR__ . '/../../../Configuration/FlexForms/*.xml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[Test]
    public function flexFormsExist(): void
    {
        self::assertNotEmpty(iterator_to_array(self::flexFormDataProvider()));
    }

    #[Test]
    #[DataProvider('flexFormDataProvider')]
    public function flexFormIsValidXmlWithSheets(string $file): void
    {
        $document = $this->load($file);

        self::assertSame('T3DataStructure', $document->documentElement?->nodeName);
        self::assertGreaterThan(0, (new \DOMXPath($document))->query('/T3DataStructure/sheets/*/ROOT/el')->length);
    }

    #[Test]
    #[DataProvider('flexFormDataProvider')]
    public function flexFormUsesNoTceFormsWrapper(string $file): void
    {
        self::assertSame(0, $this->load($file)->getElementsByTagName('TCEforms')->length, 'TCEforms was removed in TYPO3 v12');
    }

    #[Test]
    #[DataProvider('flexFormDataProvider')]
    public function everyFieldHasLabelAndType(string $file): void
    {
        $xpath = new \DOMXPath($this->load($file));
        foreach ($xpath->query('/T3DataStructure/sheets/*/ROOT/el/*') as $field) {
            self::assertInstanceOf(\DOMElement::class, $field);
            self::assertNotSame('', trim((string)$xpath->evaluate('string(label)', $field)), $field->nodeName . ' has a label');
            self::assertNotSame('', trim((string)$xpath->evaluate('string(config/type)', $field)), $field->nodeName . ' has a type');
        }
    }

    #[Test]
    #[DataProvider('flexFormDataProvider')]
    public function selectItemsHaveUniqueIndexesWithLabelAndValue(string $file): void
    {
        $xpath = new \DOMXPath($this->load($file));
        foreach ($xpath->query('//config/items') as $items) {
            $field = $items->parentNode?->parentNode?->nodeName;
            $indexes = [];
            foreach ($items->childNodes as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                // Other tag names (e.g. <item>) collapse into one array key
                self::assertSame('numIndex', $item->nodeName, $field . ': items are <numIndex> elements');
                $indexes[] = $item->getAttribute('index');
                // The legacy format (numIndex 0 = label, 1 = value) is deprecated
                self::assertSame(1, $xpath->query('label', $item)->length, $field . ': item has a <label>');
                self::assertSame(1, $xpath->query('value', $item)->length, $field . ': item has a <value>');
            }
            self::assertSame(array_unique($indexes), $indexes, $field . ': item indexes are unique');
        }
    }

    private function load(string $file): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load($file), basename($file) . ' is well-formed XML');
        return $document;
    }
}
