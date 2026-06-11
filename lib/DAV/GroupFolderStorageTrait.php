<?php
declare(strict_types=1);

namespace OCA\FolderProtection\DAV;

/**
 * Shared helper for traversing the storage wrapper chain to locate a GroupFolder storage.
 * Used by ProtectionPlugin, ProtectionPropertyPlugin, and LockPlugin.
 */
trait GroupFolderStorageTrait {
    private function getGroupFolderIdFromStorage($storage): ?int {
        $curr  = $storage;
        $depth = 0;
        while ($curr !== null && $depth < 20) {
            if (method_exists($curr, 'getFolderId')) {
                return (int)$curr->getFolderId();
            }
            $curr = method_exists($curr, 'getWrapperStorage') ? $curr->getWrapperStorage() : null;
            $depth++;
        }
        return null;
    }

    /**
     * Returns all DB path candidates for a DAV node, covering every storage format:
     *  - mount-point format ('/files/team/sub', '/exttest/sub') — file-picker entries
     *  - canonical + bare variants for home storage ('/files/x' and '/x') — backward compat
     *  - group folder ID format ('/__groupfolders/1/sub') — admin-section entries
     *
     * All candidates carry a leading slash; ProtectionChecker::normalizePath() makes the
     * leading slash irrelevant, so callers may pass them straight to isProtected() etc.
     *
     * Shared by ProtectionPlugin, ProtectionPropertyPlugin and LockPlugin to avoid drift
     * between three near-identical copies of this resolution logic.
     */
    private function getNodePathCandidates(\Sabre\DAV\INode $node): array {
        try {
            if (!method_exists($node, 'getFileInfo')) {
                return [];
            }
            $fileInfo     = $node->getFileInfo();
            $internalPath = $fileInfo->getInternalPath();
            $candidates   = [];

            // Primary: mount-point format — matches file-picker stored paths
            $mountSuffix = preg_replace('#^/[^/]+#', '', rtrim($fileInfo->getMountPoint()->getMountPoint(), '/'));
            if ($mountSuffix !== '') {
                $suffix = ltrim($mountSuffix, '/');
                $inner  = ltrim($internalPath, '/');
                $candidates[] = '/' . (($inner === '' || $inner === '.') ? $suffix : $suffix . '/' . $inner);
            } else {
                $inner = ltrim($internalPath, '/');
                if (strpos($inner, 'files/') !== 0) {
                    $candidates[] = '/files/' . $inner; // canonical /files/xxx format
                    $candidates[] = '/' . $inner;       // bare /xxx format (backward compat)
                } else {
                    $candidates[] = '/' . $inner;                          // /files/xxx format
                    $candidates[] = '/' . substr($inner, strlen('files/')); // bare /xxx (backward compat)
                }
            }

            // Secondary: group folder ID format — matches admin-section root entries
            $folderId = $this->getGroupFolderIdFromStorage($fileInfo->getStorage());
            if ($folderId !== null) {
                $inner  = ltrim($internalPath, '/');
                $idPath = '/__groupfolders/' . $folderId;
                if ($inner !== '' && $inner !== '.') {
                    $idPath .= '/' . $inner;
                }
                $candidates[] = $idPath;
            }

            return array_values(array_unique($candidates));
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->error('FolderProtection: Error getting node path candidates', [
                    'exception' => $e->getMessage(),
                ]);
            }
            return [];
        }
    }
}
