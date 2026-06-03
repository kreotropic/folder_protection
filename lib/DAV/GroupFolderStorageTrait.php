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
}
