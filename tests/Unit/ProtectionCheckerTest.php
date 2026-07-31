<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Tests\Unit;

use OCA\FolderProtection\ProtectionChecker;
use OCP\IDBConnection;
use OCP\ICacheFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ProtectionCheckerTest extends TestCase {

    private ArrayCache $cache;
    private ICacheFactory&MockObject $cacheFactory;

    protected function setUp(): void {
        $this->cache        = new ArrayCache();
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a ProtectionChecker backed by a DB that returns $rows for every
     * executeQuery() call (used for getProtectedFolders / checkDatabaseExact).
     *
     * @param array<array<string,mixed>> $rows  Rows returned by fetch()
     */
    private function checkerWithRows(array $rows): ProtectionChecker {
        $db = $this->dbReturning($rows);
        return new ProtectionChecker($db, $this->cacheFactory);
    }

    /**
     * Build IDBConnection mock whose query builder returns $rows on executeQuery.
     * Each getQueryBuilder() call produces a FRESH query builder + result so that
     * tests which trigger multiple DB queries (isProtectedOrParentProtected loops)
     * don't run out of configured return values.
     */
    private function dbReturning(array $rows): IDBConnection&MockObject {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () use ($rows) {
            $result = $this->createMock(IResult::class);
            if (empty($rows)) {
                // Always return false — handles unlimited queries safely
                $result->method('fetch')->willReturn(false);
            } else {
                $result->method('fetch')
                       ->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
            }
            $result->method('closeCursor')->willReturn(true);

            $expr = $this->createMock(IExpressionBuilder::class);
            $expr->method('eq')->willReturn('1=1');

            $qb = $this->createMock(IQueryBuilder::class);
            $qb->method('select')->willReturnSelf();
            $qb->method('from')->willReturnSelf();
            $qb->method('where')->willReturnSelf();
            $qb->method('orderBy')->willReturnSelf();
            $qb->method('createNamedParameter')->willReturnArgument(0);
            $qb->method('expr')->willReturn($expr);
            $qb->method('executeQuery')->willReturn($result);
            return $qb;
        });
        return $db;
    }

    // -------------------------------------------------------------------------
    // normalizePath
    // -------------------------------------------------------------------------

    public function testNormalizeEmptyString(): void {
        $checker = $this->checkerWithRows([]);
        $this->assertSame('/', $checker->normalizePath(''));
    }

    public function testNormalizeAddsLeadingSlash(): void {
        $checker = $this->checkerWithRows([]);
        $this->assertSame('/files/folder', $checker->normalizePath('files/folder'));
    }

    public function testNormalizeStripsTrailingSlash(): void {
        $checker = $this->checkerWithRows([]);
        $this->assertSame('/files/folder', $checker->normalizePath('/files/folder/'));
    }

    public function testNormalizeIdempotent(): void {
        $checker = $this->checkerWithRows([]);
        $path = '/files/a/b/c';
        $this->assertSame($path, $checker->normalizePath($path));
        $this->assertSame($path, $checker->normalizePath($checker->normalizePath($path)));
    }

    public function testNormalizeCollapsesDuplicateSlashes(): void {
        $checker = $this->checkerWithRows([]);
        // '/a//b' must hash the same as '/a/b' (the DAV layer only ever sees clean paths)
        $this->assertSame('/files/a/b', $checker->normalizePath('/files//a///b'));
        $this->assertSame('/files/folder', $checker->normalizePath('files//folder//'));
        $this->assertSame('/', $checker->normalizePath('///'));
    }

    // -------------------------------------------------------------------------
    // isProtected — cache behaviour
    // -------------------------------------------------------------------------

    public function testIsProtectedCacheMiss_FoundInDB(): void {
        $checker = $this->checkerWithRows([['id' => 1]]);
        $this->assertTrue($checker->isProtected('/files/folder'));
    }

    public function testIsProtectedCacheMiss_NotInDB(): void {
        $checker = $this->checkerWithRows([]);
        $this->assertFalse($checker->isProtected('/files/folder'));
    }

    public function testIsProtectedCacheHit_ReturnsWithoutDB(): void {
        // Prime the cache manually — DB would return empty, but the cache says "1"
        $path     = '/files/folder';
        $cacheKey = 'protected_' . md5($path);
        $this->cache->set($cacheKey, 1);

        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        $checker = new ProtectionChecker($db, $this->cacheFactory);
        $this->assertTrue($checker->isProtected($path));
    }

    // -------------------------------------------------------------------------
    // isProtectedOrParentProtected
    // -------------------------------------------------------------------------

    public function testParentProtected_DirectMatch(): void {
        // /files/folder is protected — the path itself should be caught
        $this->cache->set('protected_' . md5('/files/folder'), 1);
        $checker = new ProtectionChecker($this->createMock(IDBConnection::class), $this->cacheFactory);
        $this->assertTrue($checker->isProtectedOrParentProtected('/files/folder'));
    }

    public function testParentProtected_ParentIsProtected(): void {
        // /files/folder is protected — a child should also return true.
        // The initial check for the full path is a cache miss (DB returns empty),
        // then the loop finds /files/folder in the cache.
        $this->cache->set('protected_' . md5('/files/folder'), 1);
        $checker = $this->checkerWithRows([]); // DB returns nothing (path not in DB)
        $this->assertTrue($checker->isProtectedOrParentProtected('/files/folder/child/deep.txt'));
    }

    public function testParentProtected_NoMatchAnywhere(): void {
        // Nothing is protected — all cache misses return 0 from DB (empty rows)
        $checker = $this->checkerWithRows([]);
        $this->assertFalse($checker->isProtectedOrParentProtected('/files/folder/child'));
    }

    public function testParentProtected_NoDuplicateCheckOfFullPath(): void {
        // Bug 6 fix: the loop should NOT re-check the full path (it was already
        // checked at the start). We verify by counting DB calls: for path /a/b/c,
        // we expect exactly 3 calls (the initial check + /a + /a/b), NOT 4.
        $callCount = 0;

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return false; // not protected
        });
        $result->method('closeCursor')->willReturn(true);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $checker = new ProtectionChecker($db, $this->cacheFactory);
        $checker->isProtectedOrParentProtected('/a/b/c');

        // /a/b/c (initial) + /a + /a/b = 3 DB calls (not 4 which would be the bug)
        $this->assertSame(3, $callCount);
    }

    // -------------------------------------------------------------------------
    // isAnyProtectedWithBasename
    // -------------------------------------------------------------------------

    public function testBasenameMatch(): void {
        // getProtectedFolders returns '/files/Templates/Template'
        $checker = $this->checkerWithRows([['path' => '/files/Templates/Template']]);
        $this->assertTrue($checker->isAnyProtectedWithBasename('Template'));
    }

    public function testBasenameNoMatch(): void {
        $checker = $this->checkerWithRows([['path' => '/files/Templates/Template']]);
        $this->assertFalse($checker->isAnyProtectedWithBasename('Other'));
    }

    public function testBasenameEmpty(): void {
        $checker = $this->checkerWithRows([['path' => '/files/Templates/Template']]);
        $this->assertFalse($checker->isAnyProtectedWithBasename(''));
    }

    public function testBasenameIsCaseSensitive(): void {
        $checker = $this->checkerWithRows([['path' => '/files/Templates/Template']]);
        $this->assertFalse($checker->isAnyProtectedWithBasename('template'));
    }

    public function testBasenameUsesCache(): void {
        // Prime cache so DB is never queried
        $this->cache->set('all_protected_folders', json_encode(['/files/Folder']));
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        $checker = new ProtectionChecker($db, $this->cacheFactory);
        $this->assertTrue($checker->isAnyProtectedWithBasename('Folder'));
    }

    // -------------------------------------------------------------------------
    // hasProtectedDescendant
    // -------------------------------------------------------------------------

    /**
     * hasProtectedDescendant now answers from the cached folder list — it runs once per
     * node on every PROPFIND, so a COUNT(*) per call was not affordable. These tests seed
     * the cache and assert the DB is never touched.
     */
    private function checkerWithProtected(array $paths): ProtectionChecker {
        $this->cache->set('all_protected_folders', json_encode($paths));
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        return new ProtectionChecker($db, $this->cacheFactory);
    }

    public function testHasProtectedDescendant_True(): void {
        $checker = $this->checkerWithProtected(['/files/A/B/C']);
        $this->assertTrue($checker->hasProtectedDescendant('/files/A'));
        $this->assertTrue($checker->hasProtectedDescendant('/files/A/B'));
    }

    public function testHasProtectedDescendant_False(): void {
        $checker = $this->checkerWithProtected(['/files/A/B/C']);
        // The protected path itself is not its own descendant
        $this->assertFalse($checker->hasProtectedDescendant('/files/A/B/C'));
        $this->assertFalse($checker->hasProtectedDescendant('/files/X'));
    }

    /**
     * Prefix matching must respect path segments: '/files/A' must not be treated as an
     * ancestor of '/files/AB/C'. The old LIKE query had the mirror-image hazard, where an
     * unescaped '_' in a folder name matched any character.
     */
    public function testHasProtectedDescendant_DoesNotMatchPartialSegment(): void {
        $checker = $this->checkerWithProtected(['/files/AB/C']);
        $this->assertFalse($checker->hasProtectedDescendant('/files/A'));
        $this->assertTrue($checker->hasProtectedDescendant('/files/AB'));
    }

    public function testHasProtectedDescendant_UnderscoreIsLiteral(): void {
        $checker = $this->checkerWithProtected(['/files/my_folder/sub']);
        $this->assertTrue($checker->hasProtectedDescendant('/files/my_folder'));
        $this->assertFalse($checker->hasProtectedDescendant('/files/myXfolder'));
    }

    public function testHasProtectedDescendant_RootUsesCachedList(): void {
        $checker = $this->checkerWithProtected(['/files/A']);
        $this->assertTrue($checker->hasProtectedDescendant('/'));
    }

    public function testHasProtectedDescendant_RootEmpty(): void {
        $checker = $this->checkerWithProtected([]);
        $this->assertFalse($checker->hasProtectedDescendant('/'));
    }

    // -------------------------------------------------------------------------
    // clearCacheForPath
    // -------------------------------------------------------------------------

    public function testClearCacheForPathInvalidatesRelatedKeys(): void {
        $path = '/files/folder';
        $this->cache->set('protected_' . md5($path), 1);
        $this->cache->set('folder_protection_info_' . md5($path), ['id' => 1]);
        $this->cache->set('all_protected_folders', json_encode([$path]));
        $this->cache->set('api_status_response', '{}');

        $checker = new ProtectionChecker($this->createMock(IDBConnection::class), $this->cacheFactory);
        $checker->clearCacheForPath($path);

        $this->assertNull($this->cache->get('protected_' . md5($path)));
        $this->assertNull($this->cache->get('folder_protection_info_' . md5($path)));
        $this->assertNull($this->cache->get('all_protected_folders'));
        $this->assertNull($this->cache->get('api_status_response'));
    }

    public function testClearCacheDoesNotAffectOtherPaths(): void {
        $path      = '/files/folder';
        $otherPath = '/files/other';
        $this->cache->set('protected_' . md5($otherPath), 1);

        $checker = new ProtectionChecker($this->createMock(IDBConnection::class), $this->cacheFactory);
        $checker->clearCacheForPath($path);

        // The other path's cache entry should be untouched
        $this->assertSame(1, $this->cache->get('protected_' . md5($otherPath)));
    }
}
