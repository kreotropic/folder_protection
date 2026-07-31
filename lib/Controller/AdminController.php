<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Controller;

use OCA\FolderProtection\ProtectionChecker;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Endpoints de administração da app.
 *
 * Segurança: no Nextcloud o comportamento por omissão de um método de controller é
 * "admin required" (SecurityMiddleware::checkSecurity). Não existe nenhum atributo
 * OCP\AppFramework\Http\Attribute\AdminRequired — apenas NoAdminRequired e
 * SubAdminRequired. Por isso os métodos restritos a admin não levam atributo nenhum;
 * só os que abrem acesso a utilizadores normais levam #[NoAdminRequired].
 *
 * #[NoCSRFRequired] só é usado em GETs read-only. Os POSTs que alteram estado
 * (protect/unprotect/updateReason/clearCache) mantêm a verificação CSRF activa —
 * o frontend usa @nextcloud/axios, que envia o requesttoken automaticamente.
 */
class AdminController extends Controller {

    private IDBConnection $db;
    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;
    private ICacheFactory $cacheFactory;
    private IAppManager $appManager;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;

    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        IAppManager $appManager,
        IUserSession $userSession,
        IRootFolder $rootFolder
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
        $this->cacheFactory = $cacheFactory;
        $this->appManager = $appManager;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
    }

    /**
     * List all protected folders (admin only)
     */
    #[NoCSRFRequired]
    public function list(): JSONResponse {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('folder_protection')
               ->orderBy('created_at', 'DESC');

            $result = $qb->executeQuery();
            $folders = [];

            while ($row = $result->fetch()) {
                $folders[] = [
                    'id' => (int)$row['id'],
                    'path' => $row['path'],
                    'file_id' => $row['file_id'] ? (int)$row['file_id'] : null,
                    'user_id' => $row['user_id'],
                    'created_by' => $row['created_by'],
                    'created_at' => (int)$row['created_at'],
                    'reason' => $row['reason'],
                ];
            }
            $result->closeCursor();

            // annotate groupfolders with their visible mountPoint if available
            if ($this->appManager->isInstalled('groupfolders')) {
                $mountPoints = $this->fetchGroupFolderMountPoints();
                foreach ($folders as &$folder) {
                    if (preg_match('#^/__groupfolders/(\d+)(/.*)?$#', $folder['path'], $m)) {
                        $id = (int)$m[1];
                        if (isset($mountPoints[$id])) {
                            $folder['mountPoint'] = $mountPoints[$id];
                        }
                    }
                }
                unset($folder);
            }

            return new JSONResponse([
                'success' => true,
                'folders' => $folders
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error listing protected folders', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error listing protected folders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Protect a folder (admin only)
     */
    public function protect(string $path, ?string $reason = null): JSONResponse {
        try {
            $path = $this->protectionChecker->normalizePath($path);

            // Regular user-folder paths must start with /files/ to match the internal
            // path format used by StorageWrapper and DAV plugins (which always see paths
            // like 'files/folder' from the home storage). Auto-correct silently so the
            // admin does not have to know the internal convention.
            if (!str_starts_with($path, '/__groupfolders/') && !str_starts_with($path, '/files/')) {
                $path = '/files' . $path;
            }

            // Obtém o utilizador autenticado do lado do servidor (nunca do cliente)
            $userId = $this->userSession->getUser()?->getUID() ?? '';

            if ($this->protectionChecker->isProtected($path)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Folder is already protected'
                ], 400);
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('folder_protection')
               ->values([
                   'path'      => $qb->createNamedParameter($path),
                   'path_hash' => $qb->createNamedParameter(md5($path)),
                   'user_id'   => $qb->createNamedParameter($userId),
                   'created_by' => $qb->createNamedParameter($userId),
                   'created_at' => $qb->createNamedParameter(time()),
                   'reason'    => $qb->createNamedParameter($reason),
               ]);
            $qb->executeStatement();

            $this->protectionChecker->clearCacheForPath($path);
            $this->logger->info('Protected folder', ['path' => $path, 'user' => $userId]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Folder protected successfully',
                'path' => $path
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error protecting folder', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error protecting folder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unprotect a folder (admin only)
     */
    public function unprotect(int $id): JSONResponse {
        try {
            // Busca o path antes de apagar para poder invalidar a cache específica
            $qbSelect = $this->db->getQueryBuilder();
            $qbSelect->select('path')
                     ->from('folder_protection')
                     ->where($qbSelect->expr()->eq('id', $qbSelect->createNamedParameter($id)));
            $selectResult = $qbSelect->executeQuery();
            $row = $selectResult->fetch();
            $selectResult->closeCursor();

            $qb = $this->db->getQueryBuilder();
            $qb->delete('folder_protection')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

            $affected = $qb->executeStatement();

            if ($affected === 0) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Folder protection not found'
                ], 404);
            }

            if ($row && isset($row['path'])) {
                $this->protectionChecker->clearCacheForPath($row['path']);
            } else {
                // Fallback: limpa tudo se não encontrou o path
                $this->protectionChecker->clearCache();
            }
            $this->logger->info('Unprotected folder', ['id' => $id]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Folder unprotected successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error unprotecting folder', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error unprotecting folder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a path is protected (accessible to all users — read-only)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function check(string $path): JSONResponse {
        try {
            $path = $this->protectionChecker->normalizePath($path);
            $isProtected = $this->protectionChecker->isProtected($path);

            return new JSONResponse([
                'success' => true,
                'path' => $path,
                'protected' => $isProtected
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error checking folder protection', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error checking folder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear protection cache (admin only)
     */
    public function clearCache(): JSONResponse {
        try {
            $this->clearCacheInternal();

            return new JSONResponse([
                'success' => true,
                'message' => 'Cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error clearing cache', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error clearing cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all group folders with their protection status (admin only)
     */
    #[NoCSRFRequired]
    public function listGroupFolders(): JSONResponse {
        try {
            if (!$this->appManager->isInstalled('groupfolders')) {
                return new JSONResponse([
                    'success' => true,
                    'available' => false,
                    'folders' => [],
                ]);
            }

            // Fetch all group folders
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id', 'mount_point')->from('group_folders');
            $result = $qb->executeQuery();
            $groupFolders = [];
            while ($row = ($result->fetch())) {
                $groupFolders[(int)$row['folder_id']] = $row['mount_point'];
            }
            $result->closeCursor();

            // Fetch group folders protected via proper path (/__groupfolders/{id})
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('id', 'path', 'reason', 'created_by')
                ->from('folder_protection')
                ->where($qb2->expr()->like('path', $qb2->createNamedParameter($this->db->escapeLikeParameter('/__groupfolders/') . '%')));
            $result2 = $qb2->executeQuery();
            $protected = [];
            while ($row = $result2->fetch()) {
                if (preg_match('#^/__groupfolders/(\d+)$#', $row['path'], $m)) {
                    $protected[(int)$m[1]] = [
                        'protection_id' => (int)$row['id'],
                        'reason'        => $row['reason'],
                        'created_by'    => $row['created_by'],
                    ];
                }
            }
            $result2->closeCursor();

            // Detect group folders "protected" via /files/{mountPoint} (custom path — incomplete protection)
            // These block basename creation but do NOT protect the actual group folder from DAV operations.
            $qb3 = $this->db->getQueryBuilder();
            $qb3->select('id', 'path', 'reason', 'created_by')
                ->from('folder_protection')
                ->where($qb3->expr()->like('path', $qb3->createNamedParameter($this->db->escapeLikeParameter('/files/') . '%')));
            $result3 = $qb3->executeQuery();
            $customPathByName = [];
            while ($row = ($result3->fetch())) {
                $basename = basename($row['path']);
                $customPathByName[$basename] = [
                    'protection_id' => (int)$row['id'],
                    'reason'        => $row['reason'],
                    'created_by'    => $row['created_by'],
                    'path'          => $row['path'],
                ];
            }
            $result3->closeCursor();

            $folders = [];
            foreach ($groupFolders as $id => $mountPoint) {
                $isProtected        = isset($protected[$id]);
                $isPartial          = !$isProtected && isset($customPathByName[$mountPoint]);
                $data               = $isProtected ? $protected[$id] : ($isPartial ? $customPathByName[$mountPoint] : null);
                $folders[] = [
                    'id'               => $id,
                    'mountPoint'       => $mountPoint,
                    'path'             => '/__groupfolders/' . $id,
                    'protected'        => $isProtected || $isPartial,
                    'partialProtection'=> $isPartial,
                    'protectionId'     => $data ? $data['protection_id'] : null,
                    'reason'           => $data ? $data['reason'] : null,
                    'createdBy'        => $data ? $data['created_by'] : null,
                ];
            }

            return new JSONResponse([
                'success'   => true,
                'available' => true,
                'folders'   => $folders,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error listing group folders', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error listing group folders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the reason for an existing protection (admin only)
     */
    public function updateReason(int $id, ?string $reason = null): JSONResponse {
        try {
            // Fetch path first so we can invalidate the exact cache entry after update
            $qbSelect = $this->db->getQueryBuilder();
            $qbSelect->select('path')
                     ->from('folder_protection')
                     ->where($qbSelect->expr()->eq('id', $qbSelect->createNamedParameter($id)));
            $selectResult = $qbSelect->executeQuery();
            $row = $selectResult->fetch();
            $selectResult->closeCursor();

            $qb = $this->db->getQueryBuilder();
            $qb->update('folder_protection')
               ->set('reason', $qb->createNamedParameter($reason))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

            $affected = $qb->executeStatement();

            if ($affected === 0) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Folder protection not found'
                ], 404);
            }

            if ($row && isset($row['path'])) {
                $this->protectionChecker->clearCacheForPath($row['path']);
            } else {
                $this->protectionChecker->clearCache();
            }

            $this->logger->info('Updated reason for folder protection', ['id' => $id]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Reason updated successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error updating reason', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error updating reason: ' . $e->getMessage()
            ], 500);
        }
    }

    private function clearCacheInternal(): void {
        $this->protectionChecker->clearCache();
        $this->cacheFactory->createDistributed('folder_protection')->remove('api_status_response');
    }

    /**
     * Check if a custom path exists in the filesystem (admin only).
     * Used by the admin UI to warn before protecting a non-existent folder.
     * Checks against the currently logged-in admin's user folder.
     * Group folder paths (/__groupfolders/...) are always reported as existing.
     */
    #[NoCSRFRequired]
    public function checkExists(string $path): JSONResponse {
        try {
            $path = $this->protectionChecker->normalizePath($path);

            // Group folder paths are not in any user's home — always allow
            if (str_starts_with($path, '/__groupfolders/')) {
                return new JSONResponse(['exists' => true]);
            }

            $userId = $this->userSession->getUser()?->getUID();
            if (!$userId) {
                return new JSONResponse(['exists' => null]);
            }

            // Convert /files/A/B → /A/B for getUserFolder lookup
            $relativePath = preg_replace('#^/files#', '', $path);

            try {
                $userFolder = $this->rootFolder->getUserFolder($userId);
                if (!$userFolder->nodeExists($relativePath)) {
                    return new JSONResponse(['exists' => false]);
                }
                $node = $userFolder->get($relativePath);
                return new JSONResponse([
                    'exists' => $node instanceof Folder,
                    'isFile' => !($node instanceof Folder),
                ]);
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['exists' => false]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error checking folder existence', ['exception' => $e->getMessage()]);
            return new JSONResponse(['exists' => null]);
        }
    }

    /**
     * List immediate subfolders of a given path for the folder tree picker.
     * Returns folder names, their DB path, protection status, and whether they have children.
     */
    #[NoCSRFRequired]
    public function browse(string $path = '/'): JSONResponse {
        $userId = $this->userSession->getUser()?->getUID();
        if (!$userId) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $normalized = preg_replace('#^/files#', '', $this->protectionChecker->normalizePath($path));
        if ($normalized === '') {
            $normalized = '/';
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node = ($normalized === '/') ? $userFolder : $userFolder->get($normalized);

            if (!($node instanceof Folder)) {
                return new JSONResponse(['error' => 'not_a_folder'], 400);
            }

            $items = [];
            foreach ($node->getDirectoryListing() as $child) {
                if (!($child instanceof Folder)) {
                    continue;
                }
                if (str_starts_with($child->getName(), '.')) {
                    continue;
                }
                // getPath() returns /userId/files/A/B — convert to DB format /files/A/B
                $childDbPath = preg_replace('#^/[^/]+/files#', '/files', $child->getPath());
                $hasChildren = count(array_filter(
                    $child->getDirectoryListing(),
                    fn($n) => $n instanceof Folder && !str_starts_with($n->getName(), '.')
                )) > 0;
                $items[] = [
                    'name'        => $child->getName(),
                    'path'        => $childDbPath,
                    'isProtected' => $this->protectionChecker->isProtected($childDbPath),
                    'hasChildren' => $hasChildren,
                ];
            }

            usort($items, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            $currentDbPath = '/files' . ($normalized === '/' ? '' : $normalized);
            return new JSONResponse(['items' => $items, 'path' => $currentDbPath]);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(['error' => 'not_found'], 404);
        } catch (\Exception $e) {
            $this->logger->error('Error browsing folder', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'internal'], 500);
        }
    }

    /**
     * Get protection status for all folders (accessible to all users — used by UI badges)
     *
     * For group folder paths (/__groupfolders/N), also emits an alias at /files/<mountPoint>
     * so that folder-protection-ui.js (which builds /files/<name> paths) can match them.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getFolderStatuses(): JSONResponse {
        try {
            $cache    = $this->cacheFactory->createDistributed('folder_protection');
            $cacheKey = 'api_status_response';
            $cached   = $cache->get($cacheKey);
            if ($cached !== null) {
                return new JSONResponse(json_decode($cached, true));
            }

            // This endpoint is NoAdminRequired (any logged-in user can call it for the
            // file-list lock badges). Expose ONLY the paths — never the reason or the
            // created_by, which would leak who protected what to every user.
            $qb = $this->db->getQueryBuilder();
            $qb->select('path')
                ->from('folder_protection');

            $result = $qb->executeQuery();
            $protections = [];

            while ($row = $result->fetch()) {
                $protections[$row['path']] = [
                    'protected' => true,
                ];
            }
            $result->closeCursor();

            // For group folder paths, also expose their visible mount-point path
            // so the web UI can mark them with the lock icon.
            if ($this->appManager->isInstalled('groupfolders')) {
                $mountPoints = $this->fetchGroupFolderMountPoints();
                $aliases = [];
                foreach ($protections as $path => $info) {
                    if (preg_match('#^/__groupfolders/(\d+)(/.*)?$#', $path, $m)) {
                        $folderId = (int)$m[1];
                        if (isset($mountPoints[$folderId])) {
                            $subPath = $m[2] ?? '';
                            $aliases['/files/' . $mountPoints[$folderId] . $subPath] = $info;
                        }
                    }
                }
                $protections = array_merge($protections, $aliases);
            }

            $payload = ['success' => true, 'protections' => $protections];
            $cache->set($cacheKey, json_encode($payload), 120); // 2-minute TTL

            return new JSONResponse([
                'success' => true,
                'protections' => $protections
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error getting folder statuses', [
                'exception' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Returns a map of folder_id => mount_point from the group_folders table.
     * Only call this when the groupfolders app is confirmed to be installed.
     *
     * @return array<int, string>
     */
    private function fetchGroupFolderMountPoints(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')->from('group_folders');
        $result = $qb->executeQuery();
        $map = [];
        while ($row = ($result->fetch())) {
            $map[(int)$row['folder_id']] = $row['mount_point'];
        }
        $result->closeCursor();
        return $map;
    }
}
