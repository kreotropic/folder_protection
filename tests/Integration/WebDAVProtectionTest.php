<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests that simulate the exact WebDAV operations performed by the
 * Nextcloud Windows desktop client when interacting with protected folders.
 *
 * Prerequisites (set via environment variables):
 *   FP_TEST_BASE_URL  — Nextcloud base URL  (default: http://localhost:8080)
 *   FP_TEST_USER      — Admin user           (default: ncadmin)
 *   FP_TEST_PASSWORD  — Admin password       (REQUIRED — tests are skipped if absent)
 *
 * Run with:
 *   vendor/bin/phpunit -c phpunit.integration.xml
 */
class WebDAVProtectionTest extends TestCase {

    private string $baseUrl;
    private string $user;
    private string $password;
    private string $davBase;   // WebDAV root for the test user
    private string $apiBase;   // Folder Protection REST API base

    /** IDs of protections created during tests — cleaned up in tearDown */
    private array $createdProtectionIds = [];

    /** WebDAV paths created during tests — cleaned up in tearDown */
    private array $createdDavPaths = [];

    /** Unique suffix to avoid name collisions between test runs */
    private string $runId;

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void {
        $password = getenv('FP_TEST_PASSWORD');
        if ($password === false || $password === '') {
            $this->markTestSkipped(
                'Set FP_TEST_PASSWORD to run integration tests. ' .
                'Example: FP_TEST_PASSWORD=secret vendor/bin/phpunit -c phpunit.integration.xml'
            );
        }

        $this->baseUrl  = rtrim(getenv('FP_TEST_BASE_URL') ?: 'http://localhost:8080', '/');
        $this->user     = getenv('FP_TEST_USER') ?: 'ncadmin';
        $this->password = $password;
        $this->runId    = substr(md5((string)microtime(true)), 0, 6);
        $this->davBase  = $this->baseUrl . '/remote.php/dav/files/' . $this->user;
        $this->apiBase  = $this->baseUrl . '/apps/folder_protection';

        // Verify the server is reachable
        $status = $this->dav('PROPFIND', '/', [], null, 1);
        if ($status['http_code'] === 0) {
            $this->markTestSkipped("Nextcloud server not reachable at {$this->baseUrl}");
        }
    }

    protected function tearDown(): void {
        // Remove protections created during tests
        foreach ($this->createdProtectionIds as $id) {
            $this->api('POST', '/api/unprotect', ['id' => $id]);
        }
        $this->createdProtectionIds = [];

        // Delete folders created during tests (reverse order for nested paths)
        foreach (array_reverse($this->createdDavPaths) as $path) {
            $this->dav('DELETE', $path);
        }
        $this->createdDavPaths = [];
    }

    // -------------------------------------------------------------------------
    // Tests — DELETE
    // -------------------------------------------------------------------------

    public function testDeleteProtectedFolderIsBlocked(): void {
        $this->setupProtectedFolder('/TestProt_Delete_{$this->runId}');

        $response = $this->dav('DELETE', '/TestProt_Delete_{$this->runId}');

        $this->assertSame(403, $response['http_code'],
            'DELETE of protected folder must return 403');
        $this->assertFolderExists('/TestProt_Delete_{$this->runId}',
            'Protected folder must still exist after blocked DELETE');
    }

    // -------------------------------------------------------------------------
    // Tests — MOVE
    // -------------------------------------------------------------------------

    public function testMoveProtectedFolderOutOfScopeIsBlocked(): void {
        $this->createFolder('/TestProt_Move_Parent_{$this->runId}');
        $this->createFolder('/TestProt_Move_Parent_{$this->runId}/Protected');
        $this->protectPath('/files/TestProt_Move_Parent_{$this->runId}/Protected');

        $response = $this->dav('MOVE', '/TestProt_Move_Parent_{$this->runId}/Protected', [
            'Destination' => $this->davBase . '/TestProt_Move_Parent_{$this->runId}/Protected_Moved',
        ]);
        // Cleanup: the destination may or may not have been created
        $this->createdDavPaths[] = '/TestProt_Move_Parent_{$this->runId}/Protected_Moved';

        $this->assertSame(403, $response['http_code'],
            'MOVE of protected folder must return 403');
        $this->assertFolderExists('/TestProt_Move_Parent_{$this->runId}/Protected',
            'Protected folder must remain at original location');
    }

    public function testMoveInsideProtectedFolderIsAllowed(): void {
        // Renaming a file that already exists INSIDE a protected folder must still work.
        // Important: the file must be created BEFORE the folder is protected, because
        // protecting a folder marks it as non-creatable (getPermissions returns READ|SHARE
        // for the folder node itself, blocking new-file creation via the parent permission check).
        $this->createFolder('/TestProt_MoveInside_{$this->runId}');
        $this->createFile('/TestProt_MoveInside_{$this->runId}/original.txt', 'content');
        $this->protectPath('/files/TestProt_MoveInside_{$this->runId}');

        $response = $this->dav('MOVE', '/TestProt_MoveInside_{$this->runId}/original.txt', [
            'Destination' => $this->davBase . '/TestProt_MoveInside_{$this->runId}/renamed.txt',
        ]);
        $this->createdDavPaths[] = '/TestProt_MoveInside_{$this->runId}/renamed.txt';

        $this->assertContains($response['http_code'], [201, 204],
            'MOVE within protected folder scope must be allowed');
    }

    // -------------------------------------------------------------------------
    // Tests — COPY
    // -------------------------------------------------------------------------

    public function testCopyProtectedFolderIsBlocked(): void {
        $this->setupProtectedFolder('/TestProt_Copy_{$this->runId}');

        $response = $this->dav('COPY', '/TestProt_Copy_{$this->runId}', [
            'Destination' => $this->davBase . '/TestProt_Copy_{$this->runId}_Dest',
        ]);
        $this->createdDavPaths[] = '/TestProt_Copy_{$this->runId}_Dest';

        $this->assertSame(423, $response['http_code'],
            'COPY of protected folder must return 423 Locked');
        $this->assertFolderNotExists('/TestProt_Copy_{$this->runId}_Dest',
            'Copy destination must not be created');
    }

    // -------------------------------------------------------------------------
    // Tests — MKCOL basename guard (stepping-stone prevention)
    // -------------------------------------------------------------------------

    /**
     * When a protected folder named "Template" exists at /Templates/Template,
     * attempting to create a NEW folder also named "Template" at a DIFFERENT
     * location must be blocked — this is the stepping-stone that the Windows
     * client creates before sending MOVE.
     */
    public function testMKCOLWithSameBasenameAsProtectedFolderIsBlocked(): void {
        $this->createFolder('/TestProt_BasenameParent_{$this->runId}');
        $this->createFolder('/TestProt_BasenameParent_{$this->runId}/UniqueFolder99_{$this->runId}');
        $this->protectPath('/files/TestProt_BasenameParent_{$this->runId}/UniqueFolder99_{$this->runId}');

        // Attempt to create a folder with the same basename at a different location
        $response = $this->dav('MKCOL', '/UniqueFolder99_{$this->runId}');
        $this->createdDavPaths[] = '/UniqueFolder99_{$this->runId}'; // cleanup even if it was created

        $this->assertSame(403, $response['http_code'],
            'MKCOL with same basename as protected folder must return 403');
        $this->assertFolderNotExists('/UniqueFolder99_{$this->runId}',
            'Stepping-stone folder must not be created at root');
    }

    /**
     * Creating a subfolder INSIDE a protected folder must remain allowed —
     * the basename guard must not block internal organisation.
     */
    public function testMKCOLInsideProtectedFolderIsAllowed(): void {
        $this->setupProtectedFolder('/TestProt_InternalMKCOL_{$this->runId}');

        $response = $this->dav('MKCOL', '/TestProt_InternalMKCOL_{$this->runId}/SubFolder');
        $this->createdDavPaths[] = '/TestProt_InternalMKCOL_{$this->runId}/SubFolder';

        $this->assertContains($response['http_code'], [201, 405],
            'MKCOL inside protected folder must be allowed (201) or already exists (405)');
    }

    // -------------------------------------------------------------------------
    // Tests — Full Windows client sequence (MKCOL + MOVE)
    // -------------------------------------------------------------------------

    /**
     * Simulates the exact sequence the Windows desktop client uses when moving
     * a protected folder:
     *   1. MKCOL at destination (pre-creates empty stepping-stone)
     *   2. MOVE source → destination
     *
     * Expected: both steps are blocked and NO orphaned folder remains.
     */
    public function testWindowsClientMoveSequenceNoOrphanedFolder(): void {
        // Setup: create /Tests_Win_{$this->runId}/ProtectedFolder_{$this->runId} and protect it
        $this->createFolder('/Tests_Win_{$this->runId}');
        $this->createFolder('/Tests_Win_{$this->runId}/ProtectedFolder_{$this->runId}');
        $this->protectPath('/files/Tests_Win_{$this->runId}/ProtectedFolder_{$this->runId}');

        // Step 1 (Windows client): MKCOL destination at root — must be BLOCKED
        $mkcolResponse = $this->dav('MKCOL', '/ProtectedFolder_{$this->runId}');
        $this->createdDavPaths[] = '/ProtectedFolder_{$this->runId}';

        $this->assertSame(403, $mkcolResponse['http_code'],
            'Step 1 — Windows MKCOL of stepping-stone must be blocked');
        $this->assertFolderNotExists('/ProtectedFolder_{$this->runId}',
            'Step 1 — No stepping-stone must be created at root');

        // Step 2 (Windows client): MOVE — must also be BLOCKED
        $moveResponse = $this->dav('MOVE', '/Tests_Win_{$this->runId}/ProtectedFolder_{$this->runId}', [
            'Destination' => $this->davBase . '/ProtectedFolder_{$this->runId}',
        ]);

        $this->assertSame(403, $moveResponse['http_code'],
            'Step 2 — Windows MOVE must be blocked');
        $this->assertFolderExists('/Tests_Win_{$this->runId}/ProtectedFolder_{$this->runId}',
            'Step 2 — Original folder must still exist at source');
        $this->assertFolderNotExists('/ProtectedFolder_{$this->runId}',
            'Step 2 — No orphaned folder at destination');
    }

    // -------------------------------------------------------------------------
    // Tests — Regression: unprotected folders work normally
    // -------------------------------------------------------------------------

    public function testUnprotectedFolderCanBeMoved(): void {
        $this->createFolder('/TestFree_Move_Src_{$this->runId}');

        $response = $this->dav('MOVE', '/TestFree_Move_Src_{$this->runId}', [
            'Destination' => $this->davBase . '/TestFree_Move_Dst_{$this->runId}',
        ]);
        $this->createdDavPaths[] = '/TestFree_Move_Dst_{$this->runId}';

        $this->assertContains($response['http_code'], [201, 204],
            'MOVE of unprotected folder must succeed');
    }

    public function testUnprotectedFolderCanBeCopied(): void {
        $this->createFolder('/TestFree_Copy_Src_{$this->runId}');

        $response = $this->dav('COPY', '/TestFree_Copy_Src_{$this->runId}', [
            'Destination' => $this->davBase . '/TestFree_Copy_Dst_{$this->runId}',
        ]);
        $this->createdDavPaths[] = '/TestFree_Copy_Dst_{$this->runId}';

        $this->assertContains($response['http_code'], [201, 204],
            'COPY of unprotected folder must succeed');
    }

    public function testUnprotectedFolderCanBeDeleted(): void {
        $this->createFolder('/TestFree_Delete_{$this->runId}');

        $response = $this->dav('DELETE', '/TestFree_Delete_{$this->runId}');

        $this->assertContains($response['http_code'], [204, 404],
            'DELETE of unprotected folder must succeed');
        // Remove from cleanup list since it was already deleted
        $this->createdDavPaths = array_filter(
            $this->createdDavPaths,
            fn($p) => $p !== '/TestFree_Delete_{$this->runId}'
        );
    }

    // -------------------------------------------------------------------------
    // Setup helpers
    // -------------------------------------------------------------------------

    /** Create a folder and register it for cleanup. */
    private function createFolder(string $davPath): void {
        $this->dav('MKCOL', $davPath);
        $this->createdDavPaths[] = $davPath;
    }

    /** Create a text file and register it for cleanup. */
    private function createFile(string $davPath, string $content = ''): void {
        $this->dav('PUT', $davPath, ['Content-Type' => 'text/plain'], $content);
        $this->createdDavPaths[] = $davPath;
    }

    /** Create a folder and protect it via the admin API. */
    private function setupProtectedFolder(string $davPath): void {
        $this->createFolder($davPath);
        $this->protectPath('/files' . $davPath);
    }

    /** Protect a path via the admin API and register the protection ID for cleanup. */
    private function protectPath(string $path, string $reason = 'integration-test'): void {
        $this->api('POST', '/api/protect', ['path' => $path, 'reason' => $reason]);

        // The protect endpoint doesn't return the new ID, so always fetch it via the list.
        // This also handles the case where the path was already protected (e.g. from a
        // previous test run that didn't clean up), ensuring tearDown can always unprotect it.
        $list = json_decode($this->api('GET', '/api/list')['body'], true);
        foreach ($list['folders'] ?? [] as $folder) {
            if ($folder['path'] === $path && !in_array((int)$folder['id'], $this->createdProtectionIds, true)) {
                $this->createdProtectionIds[] = (int)$folder['id'];
                break;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    private function assertFolderExists(string $davPath, string $message = ''): void {
        $response = $this->dav('PROPFIND', $davPath, [], null, 0);
        $this->assertSame(207, $response['http_code'],
            ($message ?: "Folder $davPath should exist") . " (PROPFIND returned {$response['http_code']})");
    }

    private function assertFolderNotExists(string $davPath, string $message = ''): void {
        $response = $this->dav('PROPFIND', $davPath, [], null, 0);
        $this->assertSame(404, $response['http_code'],
            ($message ?: "Folder $davPath should NOT exist") . " (PROPFIND returned {$response['http_code']})");
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Send a WebDAV request.
     *
     * @param string               $method  HTTP method
     * @param string               $path    Path relative to davBase (e.g. '/folder')
     * @param array<string,string> $headers Extra headers
     * @param string|null          $body    Request body
     * @param int                  $depth   WebDAV Depth header (default 0)
     * @return array{http_code: int, body: string}
     */
    private function dav(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        int $depth = 0
    ): array {
        $url = $this->davBase . $path;
        return $this->request($method, $url, array_merge(['Depth' => (string)$depth], $headers), $body);
    }

    /**
     * Call the Folder Protection admin REST API.
     *
     * @param string               $method  HTTP method
     * @param string               $endpoint Endpoint path (e.g. '/api/protect')
     * @param array<string,mixed>  $data     POST body data (JSON-encoded)
     * @return array{http_code: int, body: string}
     */
    private function api(string $method, string $endpoint, array $data = []): array {
        $url     = $this->apiBase . $endpoint;
        $headers = ['Content-Type' => 'application/json', 'OCS-APIREQUEST' => 'true'];
        $body    = $data !== [] ? json_encode($data) : null;
        return $this->request($method, $url, $headers, $body);
    }

    /**
     * Execute an HTTP request using curl.
     *
     * @return array{http_code: int, body: string}
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->user . ':' . $this->password,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }
        if ($headerLines !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body'      => $responseBody ?: '',
        ];
    }
}
