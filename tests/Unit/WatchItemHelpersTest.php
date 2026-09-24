<?php

use XcVm\Module\Watch\WatchItem;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function helpers extracted out of WatchItem::run() during the
 * duplication-removal refactor. No DB, no TMDB, no filesystem — plain
 * array-in/array-out logic, so these run with zero fixtures.
 */
final class WatchItemHelpersTest extends TestCase {

    // --- extractTopCast / extractTopDirectors -----------------------------

    public function testExtractTopCastCapsAtTheLimit(): void {
        $rCredits = array('cast' => array(
            array('name' => 'A'),
            array('name' => 'B'),
            array('name' => 'C'),
            array('name' => 'D'),
            array('name' => 'E'),
            array('name' => 'F'),
        ));
        $this->assertSame(array('A', 'B', 'C', 'D', 'E'), WatchItem::extractTopCast($rCredits));
    }

    public function testExtractTopCastHonoursACustomLimit(): void {
        $rCredits = array('cast' => array(array('name' => 'A'), array('name' => 'B'), array('name' => 'C')));
        $this->assertSame(array('A', 'B'), WatchItem::extractTopCast($rCredits, 2));
    }

    public function testExtractTopCastToleratesAMissingCastKey(): void {
        $this->assertSame(array(), WatchItem::extractTopCast(array()));
    }

    public function testExtractTopDirectorsFiltersByDepartment(): void {
        $rCredits = array('crew' => array(
            array('name' => 'Editor Bob', 'department' => 'Editing', 'known_for_department' => 'Editing'),
            array('name' => 'Director Ann', 'department' => 'Directing', 'known_for_department' => 'Directing'),
            array('name' => 'Cross-credited Sam', 'department' => 'Writing', 'known_for_department' => 'Directing'),
        ));
        $this->assertSame(array('Director Ann', 'Cross-credited Sam'), WatchItem::extractTopDirectors($rCredits));
    }

    public function testExtractTopDirectorsDeduplicatesByName(): void {
        $rCredits = array('crew' => array(
            array('name' => 'Ann', 'department' => 'Directing', 'known_for_department' => 'Directing'),
            array('name' => 'Ann', 'department' => 'Directing', 'known_for_department' => 'Directing'),
        ));
        $this->assertSame(array('Ann'), WatchItem::extractTopDirectors($rCredits));
    }

    public function testExtractTopDirectorsCapsAtTheLimit(): void {
        $rCrew = array();
        foreach (range(1, 6) as $i) {
            $rCrew[] = array('name' => "Director $i", 'department' => 'Directing', 'known_for_department' => 'Directing');
        }
        $this->assertCount(5, WatchItem::extractTopDirectors(array('crew' => $rCrew)));
    }

    // --- extractTopGenreNames ------------------------------------------------

    public function testExtractTopGenreNamesCapsAtTheGivenLimit(): void {
        $rGenres = array(array('name' => 'Action'), array('name' => 'Drama'), array('name' => 'Comedy'));
        $this->assertSame(array('Action', 'Drama'), WatchItem::extractTopGenreNames($rGenres, 2));
    }

    public function testExtractTopGenreNamesWithZeroLimitReturnsNothing(): void {
        // Preserves the pre-existing series behaviour: series passes
        // $rThreadData['max_genres'] straight through, so a 0 setting means
        // the genre display list ends up empty (see resolveGenreCategoryIDs'
        // docblock for the analogous "0 disables slicing" case, which is
        // deliberately different).
        $rGenres = array(array('name' => 'Action'));
        $this->assertSame(array(), WatchItem::extractTopGenreNames($rGenres, 0));
    }

    // --- resolveGenreCategoryIDs / resolveGenreBouquetIDs -------------------

    public function testResolveGenreCategoryIDsMapsGenresThroughWatchCategories(): void {
        $rGenres = array(array('id' => 28), array('id' => 12));
        $rWatchCategoryMap = array(
            28 => array('category_id' => 5),
            12 => array('category_id' => 7),
        );
        $this->assertSame(array(5, 7), WatchItem::resolveGenreCategoryIDs($rGenres, $rWatchCategoryMap, 0, array()));
    }

    public function testResolveGenreCategoryIDsSkipsUnmappedGenres(): void {
        $rGenres = array(array('id' => 999));
        $this->assertSame(array(), WatchItem::resolveGenreCategoryIDs($rGenres, array(), 0, array()));
    }

    public function testResolveGenreCategoryIDsDeduplicatesAgainstExisting(): void {
        $rGenres = array(array('id' => 28));
        $rWatchCategoryMap = array(28 => array('category_id' => 5));
        $this->assertSame(array(5), WatchItem::resolveGenreCategoryIDs($rGenres, $rWatchCategoryMap, 0, array(5)));
    }

    public function testResolveGenreCategoryIDsRespectsThePositiveLimit(): void {
        $rGenres = array(array('id' => 1), array('id' => 2), array('id' => 3));
        $rWatchCategoryMap = array(1 => array('category_id' => 10), 2 => array('category_id' => 20), 3 => array('category_id' => 30));
        $this->assertSame(array(10, 20), WatchItem::resolveGenreCategoryIDs($rGenres, $rWatchCategoryMap, 2, array()));
    }

    public function testResolveGenreBouquetIDsMergesBouquetListsAcrossGenres(): void {
        $rGenres = array(array('id' => 28), array('id' => 12));
        $rWatchCategoryMap = array(
            28 => array('bouquets' => '[1,2]'),
            12 => array('bouquets' => '[2,3]'),
        );
        $this->assertSame(array(1, 2, 3), WatchItem::resolveGenreBouquetIDs($rGenres, $rWatchCategoryMap, 0, array()));
    }

    public function testResolveGenreBouquetIDsToleratesAMissingBouquetsColumn(): void {
        $rGenres = array(array('id' => 999));
        $this->assertSame(array(), WatchItem::resolveGenreBouquetIDs($rGenres, array(), 0, array()));
    }

    // --- parseTitle -----------------------------------------------------------

    public function testParseTitleNormalizesSeparatorsSoNumericTitlesMatch(): void {
        // Real bug this normalization exists for: parsers hand back "9-1-1"
        // and "9 1 1" depending on source; TMDb returns "9-1-1" too.
        $this->assertSame(WatchItem::parseTitle('9-1-1'), WatchItem::parseTitle('9_1_1'));
        $this->assertSame(WatchItem::parseTitle('9-1-1'), WatchItem::parseTitle('9.1.1'));
    }

    public function testParseTitleStripsPunctuationSoAbbreviationsMatch(): void {
        $this->assertSame(WatchItem::parseTitle('S.W.A.T.'), WatchItem::parseTitle('S W A T'));
    }

    public function testParseTitleIsCaseInsensitive(): void {
        $this->assertSame(WatchItem::parseTitle('The Matrix'), WatchItem::parseTitle('THE MATRIX'));
    }

    public function testParseTitleCollapsesRepeatedWhitespace(): void {
        $this->assertSame('the matrix', WatchItem::parseTitle('The   Matrix'));
    }
}
