<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\Tests\Unit\Domain\Dto;

use Nordkirche\NkcEvent\Domain\Dto\SearchRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SearchRequestTest extends UnitTestCase
{
    #[Test]
    public function datesOfTheSearchFormAreParsed(): void
    {
        $nextYear = (int)date('Y') + 1;
        $searchRequest = new SearchRequest();
        $searchRequest->setDateFrom('01.03.' . $nextYear);
        $searchRequest->setDateTo('31.03.' . $nextYear);

        self::assertSame($nextYear . '-03-01', $searchRequest->getDateFrom()?->format('Y-m-d'));
        self::assertSame($nextYear . '-03-31 23:59:59', $searchRequest->getDateTo()?->format('Y-m-d H:i:s'), 'The end date includes the whole day');
    }

    #[Test]
    public function datesOfDateInputsAreParsed(): void
    {
        $nextYear = (int)date('Y') + 1;
        $searchRequest = new SearchRequest();
        $searchRequest->setDateFrom($nextYear . '-03-01');
        $searchRequest->setDateTo($nextYear . '-03-31');

        self::assertSame($nextYear . '-03-01', $searchRequest->getDateFrom()?->format('Y-m-d'));
        self::assertSame($nextYear . '-03-31 23:59:59', $searchRequest->getDateTo()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function datesInThePastBecomeToday(): void
    {
        $searchRequest = new SearchRequest();
        $searchRequest->setDateFrom('01.01.2000');

        self::assertSame(date('Y-m-d'), $searchRequest->getDateFrom()?->format('Y-m-d'));
    }

    public static function invalidDateDataProvider(): \Generator
    {
        yield 'text' => ['morgen'];
        yield 'invalid day' => ['45.13.2026x'];
        yield 'script' => ['<script>'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidDateDataProvider')]
    public function invalidDatesAreIgnored(string $value): void
    {
        $searchRequest = new SearchRequest();
        $searchRequest->setDateFrom($value);
        $searchRequest->setDateTo($value);

        self::assertNull($searchRequest->getDateFrom());
        self::assertNull($searchRequest->getDateTo());
    }

    #[Test]
    public function decorateOnlySetsKnownNonEmptyValues(): void
    {
        $searchRequest = new SearchRequest();
        $searchRequest->decorate(['search' => 'Orgel', 'city' => '', 'unknown' => 'x', 'organizer' => '12']);

        self::assertSame('Orgel', $searchRequest->getSearch());
        self::assertSame('', $searchRequest->getCity());
        self::assertSame('12', (string)$searchRequest->getOrganizer());
    }
}
