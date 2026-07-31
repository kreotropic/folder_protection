<?php
namespace OCA\FolderProtection\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\FolderProtection\ProtectionChecker;
use OCA\FolderProtection\Service\NotificationService;
use OCP\Files\Cache\ICache;
use OCP\Files\FileInfo;
use OCP\IL10N;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception;
use Psr\Log\LoggerInterface;

/**
 * Exceção personalizada para retornar 423 Locked com mensagem customizada.
 * A classe Sabre\DAV\Exception\Locked original não aceita mensagem no construtor,
 * o que causava TypeError (Erro 500).
 */
class FolderLocked extends Exception {
    public function getHTTPCode() {
        return 423;
    }
}

/**
 * Exceção personalizada para retornar 403 Forbidden com mensagem customizada.
 *
 * Ao usar esta classe em vez de Sabre\DAV\Exception\Forbidden, o <s:exception>
 * no XML de erro será "OCA\FolderProtection\DAV\FolderProtected" — um valor que
 * o cliente Nextcloud desktop não reconhece — forçando-o a usar <s:message>
 * (que contém a nossa mensagem personalizada) em vez da string hardcoded
 * "You don't have access to this resource."
 */
class FolderProtected extends Exception {
    public function getHTTPCode() {
        return 403;
    }
}

class ProtectionPlugin extends ServerPlugin {
    use GroupFolderStorageTrait;

    /**
     * Ceiling on how many entries a single touchSubtree() pass will re-etag.
     * This app exists to protect very large folders, and the walk costs one
     * cache query per directory, so an unbounded pass on a rejected DELETE
     * could stall the request. Beyond the ceiling the client keeps stale etags
     * for the deeper entries and those need a manual re-sync.
     */
    private const TOUCH_SUBTREE_MAX_NODES = 10000;

    private $protectionChecker;
    private NotificationService $notificationService;
    private $logger;
    private $server;
    private IL10N $l10n;

    public function __construct(ProtectionChecker $protectionChecker, NotificationService $notificationService, LoggerInterface $logger, IL10N $l10n) {
        $this->protectionChecker   = $protectionChecker;
        $this->notificationService = $notificationService;
        $this->logger              = $logger;
        $this->l10n                = $l10n;
    }

    public function initialize(Server $server) {
        $this->server = $server;

        $server->on('beforeBind', [$this, 'beforeBind'], 10);
        $server->on('beforeUnbind', [$this, 'beforeUnbind'], 10);
        $server->on('beforeMove', [$this, 'beforeMove'], 10);
        $server->on('beforeCopy', [$this, 'beforeCopy'], 10);
        $server->on('propPatch', [$this, 'propPatch'], 10);
        $server->on('beforeLock', [$this, 'beforeLock'], 10);

        $this->logger->debug('FolderProtection: WebDAV plugin initialized successfully');
    }

    private function setHeaders(string $action, string $reason): void {
        $this->server->httpResponse->setHeader('X-NC-Folder-Protected', 'true');
        $this->server->httpResponse->setHeader('X-NC-Protection-Action', $action);
        $this->server->httpResponse->setHeader('X-NC-Protection-Reason', $reason);
    }

    private function sendProtectionNotification(string $path, string $action): void {
        $this->notificationService->notifyBlocked($path, $action);
    }

    private function sendErrorResponse(int $code, string $message): void {
        $this->server->httpResponse->setStatus($code);
        $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">' . "\n";
        $xml .= '  <s:exception>OCA\FolderProtection\DAV\FolderProtected</s:exception>' . "\n";
        $xml .= '  <s:message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</s:message>' . "\n";
        $xml .= '</d:error>';

        $this->server->httpResponse->setBody($xml);
    }

    /**
     * Resolves a DAV URI to all path candidates (mount-point + group folder ID formats),
     * with URL-decoded variants of each. Falls back to URL-based patterns if the node
     * cannot be resolved. The node-based candidate resolution lives in
     * GroupFolderStorageTrait::getNodePathCandidates() and is shared with the other plugins.
     */
    private function getInternalPathCandidates($uri): array {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                $candidates = $this->getNodePathCandidates($node);
                if (!empty($candidates)) {
                    return $this->buildPathsToCheck($candidates);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug("FolderProtection DAV: getNodeForPath failed for '$uri': " . $e->getMessage());
        }

        // Fallback for direct group folder URL access (/__groupfolders/{id}/...)
        if (preg_match('#^/remote\.php/(?:web)?dav/__groupfolders/(\d+)(/.*)?$#', $uri, $matches)) {
            return $this->buildPathsToCheck(['__groupfolders/' . $matches[1] . ($matches[2] ?? '')]);
        }

        // For user file Sabre paths like 'files/{username}/inner/path', strip the username
        // component so the result matches the DB format ('files/inner/path').
        // This is essential when the target node doesn't exist yet (e.g. MOVE destination):
        // getNodeForPath fails and we must reconstruct the internal path from the URI.
        $candidates = [$uri];
        if (preg_match('#^files/[^/]+/(.+)$#', $uri, $m)) {
            $inner       = $m[1]; // 'inner/path'
            $candidates[] = 'files/' . $inner;  // canonical: files/inner/path
            $candidates[] = $inner;              // bare: inner/path (backward compat)
        }

        return $this->buildPathsToCheck($candidates);
    }

    private function buildPathsToCheck(array $paths): array {
        $result = [];
        foreach ($paths as $p) {
            $result[] = $p;
            $decoded = rawurldecode($p);
            if ($decoded !== $p) {
                $result[] = $decoded;
            }
        }
        return array_unique(array_filter($result));
    }

    public function beforeBind($uri) {
        try {
            // Skip chunked upload paths — chunk names like "1", "2" etc.
            // can match protected folder basenames (e.g. GroupFolder IDs).
            // Chunked uploads assemble in a temporary uploads/ namespace and only
            // move the final file via MOVE, which is checked in beforeMove instead.
            if (preg_match('#^/?uploads/#', $uri)) {
                return;
            }

            $pathsToCheck = $this->getInternalPathCandidates($uri);
            $this->logger->debug("FolderProtection DAV: beforeBind checking " . implode('|', $pathsToCheck));

            foreach ($pathsToCheck as $candidate) {
                if ($this->protectionChecker->isProtected($candidate)) {
                    $folderName = basename($uri);
                    $this->logger->warning("FolderProtection DAV: Blocking bind in protected path: $candidate");
                    // Must throw an exception — returning false from beforeBind causes Sabre to
                    // still send 201, which confuses the desktop client into infinite retry loops.
                    $this->touchAncestors($uri);
                    $this->setHeaders('create', $this->l10n->t("The folder '%s' is protected", [$folderName]));
                    $this->sendProtectionNotification($candidate, 'create');
                    throw new FolderProtected($this->l10n->t("The folder '%s' is protected and cannot be created here.", [$folderName]));
                }
            }

            // A folder whose basename matches a protected folder elsewhere is deliberately
            // NOT blocked here. Reserving the name across the whole server is far too broad:
            // with /files/a/b/gama protected it made "gama" uncreatable anywhere, for every
            // user. The orphaned "stepping-stone" folder that this used to guard against —
            // a desktop client pre-creating the destination before sending a MOVE the server
            // then rejects — is cleaned up by deleteEmptyNode() in beforeMove instead, which
            // only touches the specific empty folder involved in the rejected move.
        } catch (\Throwable $e) {
            if ($e instanceof \Sabre\DAV\Exception) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeBind: " . $e->getMessage());
            throw new FolderProtected($this->l10n->t('Protection check failed'));
        }
    }

    public function beforeUnbind($uri) {
        try {
            $pathsToCheck = $this->getInternalPathCandidates($uri);
            foreach ($pathsToCheck as $candidate) {
                $directlyProtected = $this->protectionChecker->isProtected($candidate);
                $hasProtectedChild  = !$directlyProtected && $this->protectionChecker->hasProtectedDescendant($candidate);

                if ($directlyProtected || $hasProtectedChild) {
                    $this->touchProtectedNode($uri);
                    if ($hasProtectedChild) {
                        $this->touchSubtree($uri);
                    }

                    $reason = $this->l10n->t('Protected by server policy');
                    if ($directlyProtected) {
                        $info = $this->protectionChecker->getProtectionInfo($candidate);
                        if (is_array($info) && !empty($info['reason'])) {
                            $reason = (string)$info['reason'];
                        }
                    } else {
                        $reason = $this->l10n->t('Contains protected sub-folders');
                    }

                    $folderName = basename($uri);
                    $msg = $this->l10n->t("The folder '%s' is protected: %s", [$folderName, $reason]);
                    $this->setHeaders('delete', $msg);
                    $this->sendProtectionNotification($candidate, 'delete');
                    $this->cleanUpMoveSteppingStone();
                    $this->sendErrorResponse(403, $msg);
                    return false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("FolderProtection DAV: Error in beforeUnbind: " . $e->getMessage());
            $this->sendErrorResponse(403, $this->l10n->t('Protection check failed'));
            return false;
        }
    }

    public function beforeMove($sourcePath, $destinationPath) {
        try {
            $srcCandidates  = $this->getInternalPathCandidates($sourcePath);
            $destCandidates = $this->getInternalPathCandidates($destinationPath);

            foreach ($srcCandidates as $candidate) {
                $directlyProtected = $this->protectionChecker->isProtected($candidate);
                $insideProtected   = !$directlyProtected && $this->protectionChecker->isProtectedOrParentProtected($candidate);
                $hasProtectedChild = !$directlyProtected && !$insideProtected && $this->protectionChecker->hasProtectedDescendant($candidate);

                if (!$directlyProtected && !$insideProtected && !$hasProtectedChild) {
                    continue;
                }

                // Allow moves where both source and destination are within the same
                // protected scope (e.g. renaming a file inside a protected folder).
                if ($insideProtected) {
                    $dstProtected = false;
                    foreach ($destCandidates as $dst) {
                        if ($this->protectionChecker->isProtectedOrParentProtected($dst)) {
                            $dstProtected = true;
                            break;
                        }
                    }
                    if ($dstProtected) {
                        continue;
                    }
                }

                $this->touchProtectedNode($sourcePath);

                $reason = $this->l10n->t('Protected by server policy');
                if ($directlyProtected) {
                    $info = $this->protectionChecker->getProtectionInfo($candidate);
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                } elseif ($hasProtectedChild) {
                    $reason = $this->l10n->t('Contains protected sub-folders');
                }

                $folderName = basename($sourcePath);
                $msg = $this->l10n->t("The folder '%s' is protected: %s", [$folderName, $reason]);
                $this->setHeaders('move', $msg);
                $this->sendProtectionNotification($candidate, 'move');
                // Clean up any empty stepping-stone the client may have pre-created at
                // the destination before sending the MOVE request.
                $this->deleteEmptyNode($destinationPath);
                $this->sendErrorResponse(403, $msg);
                return false;
            }

            // Block rename/move to a protected path (prevents "create temp + rename" bypass)
            foreach ($destCandidates as $destCandidate) {
                if ($this->protectionChecker->isProtected($destCandidate)) {
                    $destName = basename($destinationPath);
                    $this->logger->warning("FolderProtection DAV: Blocking rename to protected path: $destCandidate");
                    $this->deleteEmptyNode($sourcePath);
                    $this->setHeaders('move', $this->l10n->t("Cannot rename to '%s': folder is protected", [$destName]));
                    throw new FolderProtected($this->l10n->t("Cannot rename to '%s': this folder path is protected.", [$destName]));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof \Sabre\DAV\Exception) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeMove: " . $e->getMessage());
            throw new FolderProtected($this->l10n->t('Protection check failed'));
        }
    }

    /**
     * When the refused unbind is the source half of a MOVE, drop the empty folder the
     * client has already created at the destination.
     *
     * Sabre emits beforeUnbind for the move source *before* beforeMove, so a protected
     * source aborts the request here and the equivalent cleanup in beforeMove is never
     * reached. Without this, the Windows client's "MKCOL destination, then MOVE" sequence
     * leaves an empty folder behind on every rejected drag — which is what the old
     * server-wide basename block in beforeBind was papering over.
     */
    private function cleanUpMoveSteppingStone(): void {
        $request = $this->server->httpRequest;
        if (strtoupper((string)$request->getMethod()) !== 'MOVE') {
            return;
        }

        $destination = $request->getHeader('Destination');
        if (!$destination) {
            return;
        }

        try {
            $this->deleteEmptyNode($this->server->calculateUri($destination));
        } catch (\Throwable $e) {
            $this->logger->debug('FolderProtection DAV: could not resolve MOVE destination for cleanup: ' . $e->getMessage());
        }
    }

    /**
     * Delete a node if it exists and is an empty collection.
     * Used to clean up stepping-stone folders created by the client before a blocked rename.
     */
    private function deleteEmptyNode(string $uri): void {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof \Sabre\DAV\ICollection && empty($node->getChildren())) {
                $node->delete();
                $this->logger->info("FolderProtection DAV: Deleted empty stepping-stone folder: $uri");
            }
        } catch (\Exception $e) {
            $this->logger->debug("FolderProtection DAV: Could not delete stepping-stone '$uri': " . $e->getMessage());
        }
    }

    public function beforeCopy($sourcePath, $destinationPath) {
        try {
            $srcCandidates  = $this->getInternalPathCandidates($sourcePath);
            $destCandidates = $this->getInternalPathCandidates($destinationPath);

            $this->logger->debug("FolderProtection DAV: beforeCopy checking src=" . implode('|', $srcCandidates));

            foreach ($srcCandidates as $checkSrc) {
                $directlyProtected = $this->protectionChecker->isProtected($checkSrc);
                $insideProtected   = !$directlyProtected && $this->protectionChecker->isProtectedOrParentProtected($checkSrc);
                $hasProtectedChild = !$directlyProtected && !$insideProtected && $this->protectionChecker->hasProtectedDescendant($checkSrc);

                if (!$directlyProtected && !$insideProtected && !$hasProtectedChild) {
                    continue;
                }

                // Allow copies where both source and destination are within the same
                // protected scope (e.g. copy within a protected folder).
                if ($insideProtected) {
                    $dstProtected = false;
                    foreach ($destCandidates as $dst) {
                        if ($this->protectionChecker->isProtectedOrParentProtected($dst)) {
                            $dstProtected = true;
                            break;
                        }
                    }
                    if ($dstProtected) {
                        continue;
                    }
                }

                if ($directlyProtected) {
                    $info   = $this->protectionChecker->getProtectionInfo($checkSrc);
                    $reason = (is_array($info) && !empty($info['reason'])) ? (string)$info['reason'] : 'Protected by server policy';
                } elseif ($hasProtectedChild) {
                    $reason = $this->l10n->t('Contains protected sub-folders');
                } else {
                    $reason = $this->l10n->t('Protected by server policy');
                }
                $this->logger->warning("FolderProtection DAV: Blocking copy - source protected or inside/has protected folder: $checkSrc");
                $this->setHeaders('copy', $reason);
                $this->sendProtectionNotification($checkSrc, 'copy');
                throw new FolderLocked($this->l10n->t("Cannot copy protected folder: %s", [basename($sourcePath)]));
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeCopy: " . $e->getMessage());
            throw new FolderLocked($this->l10n->t('Internal server error during protection check.'));
        }
    }

    public function propPatch($path, \Sabre\DAV\PropPatch $propPatch) {
        try {
            foreach ($this->getInternalPathCandidates($path) as $checkPath) {
                if ($this->protectionChecker->isProtected($checkPath)) {
                    $info = $this->protectionChecker->getProtectionInfo($checkPath);
                    $reason = $this->l10n->t('Protected by server policy');
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking property update on protected path: $checkPath");
                    $this->setHeaders('prop_patch', $reason);
                    $this->sendProtectionNotification($checkPath, 'prop_patch');
                    throw new FolderLocked($this->l10n->t('Cannot update properties of protected folder'));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in propPatch: " . $e->getMessage());
            throw new FolderLocked($this->l10n->t('Internal server error during protection check.'));
        }
    }

    public function beforeLock($uri, \Sabre\DAV\Locks\LockInfo $lock) {
        try {
            if ($lock->scope === \Sabre\DAV\Locks\LockInfo::EXCLUSIVE) {
                foreach ($this->getInternalPathCandidates($uri) as $checkPath) {
                    if ($this->protectionChecker->isProtected($checkPath)) {
                        $info = $this->protectionChecker->getProtectionInfo($checkPath);
                        $reason = 'Protected by server policy';
                        if (is_array($info) && !empty($info['reason'])) {
                            $reason = (string)$info['reason'];
                        }
                        $this->logger->warning("FolderProtection DAV: Blocking exclusive lock on protected path: $checkPath");
                        $this->setHeaders('lock', $reason);
                        $this->sendProtectionNotification($checkPath, 'lock');
                        throw new FolderLocked($this->l10n->t('Cannot lock items in protected folders'));
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeLock: " . $e->getMessage());
            throw new FolderLocked($this->l10n->t('Internal server error during protection check.'));
        }
    }

    private function touchProtectedNode(string $uri): void {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                $this->updateNodeCache($node);
            }

            $parentUri = dirname($uri);
            if ($parentUri && $parentUri !== '.' && $parentUri !== $uri) {
                try {
                    $parentNode = $this->server->tree->getNodeForPath($parentUri);
                    if ($parentNode instanceof Node) {
                        $this->updateNodeCache($parentNode);
                    }
                } catch (\Exception $e) {
                    // Ignora erro no pai (pode ser a raiz ou inacessível)
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("FolderProtection DAV: Failed to touch node '$uri': " . $e->getMessage());
        }
    }

    private function touchAncestors(string $uri): void {
        $current = dirname($uri);
        $depth = 0;
        while ($current && $current !== '.' && $current !== '' && $depth < 6) {
            $this->touchProtectedNode($current);
            $parent = dirname($current);
            if ($parent === $current) break;
            $current = $parent;
            $depth++;
        }
    }

    /**
     * Give every entry below $uri a fresh etag and mtime.
     *
     * Only needed when a DELETE is refused because the folder *contains* a protected
     * descendant rather than being protected itself. By then the desktop client has
     * already removed the whole subtree locally, and it only re-downloads entries whose
     * etag differs from the one in its journal. touchProtectedNode() bumps just $uri and
     * its parent, so everything underneath still looks unchanged: the client reads the
     * missing files as a local deletion to propagate, retries DELETE, gets 403 again, and
     * parks the folder in a sync error that only a manual re-copy clears. Bumping the
     * subtree makes the client treat the contents as remote changes and pull them back.
     *
     * This runs only on a rejected DELETE — rare, and always user-initiated.
     */
    private function touchSubtree(string $uri): void {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if (!$node instanceof Node) {
                return;
            }

            $cache   = $node->getFileInfo()->getStorage()->getCache();
            $pending = [$node->getFileInfo()->getId()];
            $touched = 0;

            while ($pending !== [] && $touched < self::TOUCH_SUBTREE_MAX_NODES) {
                foreach ($cache->getFolderContentsById(array_pop($pending)) as $entry) {
                    $this->updateCacheEntry($cache, $entry->getId());
                    $touched++;
                    if ($entry->getMimeType() === FileInfo::MIMETYPE_FOLDER) {
                        $pending[] = $entry->getId();
                    }
                }
            }

            if ($pending !== []) {
                $this->logger->warning(
                    "FolderProtection DAV: stopped touching '$uri' at " . self::TOUCH_SUBTREE_MAX_NODES
                    . ' entries; deeper ones keep their etag and may need a manual re-sync'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning("FolderProtection DAV: Failed to touch subtree '$uri': " . $e->getMessage());
        }
    }

    private function updateCacheEntry(ICache $cache, int $fileId): void {
        $cache->update($fileId, [
            'mtime' => time(),
            'etag'  => md5(uniqid((string)time(), true)),
        ]);
    }

    private function updateNodeCache(Node $node): void {
        $info = $node->getFileInfo();
        $newEtag = md5(uniqid((string)time(), true));
        $info->getStorage()->getCache()->update($info->getId(), [
            'mtime' => time(),
            'etag' => $newEtag
        ]);
    }

    public function getPluginName() {
        return 'folder-protection';
    }

    public function getPluginInfo() {
        return [
            'name' => $this->getPluginName(),
            'description' => 'Prevents operations on protected folders via WebDAV'
        ];
    }
}
