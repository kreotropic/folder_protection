<?php
namespace OCA\FolderProtection;

use OCA\FolderProtection\DAV\FolderLocked;
use OCP\Files\NotPermittedException;
use OC\Files\Storage\Wrapper\Wrapper;
use Psr\Log\LoggerInterface;

class StorageWrapper extends Wrapper {

    private $protectionChecker;
    /** @var string|null Mount point do storage (ex: '/ncadmin/') — usado para reconstruir o path DAV */
    private ?string $mountPoint;

    public function __construct($parameters) {
        parent::__construct($parameters);
        $this->protectionChecker = $parameters['protectionChecker'];
        $this->mountPoint = $parameters['mountPoint'] ?? null;
    }

    /**
     * Reconstructs the full user-relative path from the storage-internal path.
     *
     * For home storage ($mountPoint = '/ncadmin/'), the internal path is already
     * in the correct format: 'files/folder' → '/files/folder' in the DB.
     *
     * For external storage ($mountPoint = '/ncadmin/files/ext/'), the internal path
     * is relative to the external storage root ('subfolder'), so we must prepend the
     * mount-point suffix ('files/ext') → 'files/ext/subfolder' → '/files/ext/subfolder'.
     */
    private function buildCheckPath(string $internalPath): string {
        if ($this->mountPoint === null) {
            return $internalPath;
        }
        // Strip the username (first path component) from the mount point:
        //   /ncadmin/           → ''         (home storage — internal path already correct)
        //   /ncadmin/files/ext  → /files/ext (external — prepend to internal path)
        $mountSuffix = preg_replace('#^/[^/]+#', '', rtrim($this->mountPoint, '/'));

        if ($mountSuffix === '') {
            return $internalPath;
        }

        $suffix = ltrim($mountSuffix, '/');
        $inner  = ltrim($internalPath, '/');

        // Storage root (empty or '.') maps to the mount point path itself.
        // Protection of the mount point is handled by the parent (home) storage wrapper
        // via the full path (e.g. 'files/exttest'). Returning '' here means isProtected('/')
        // which is never protected, so getPermissions/isDeletable/etc. stay unrestricted
        // and operations on the storage contents remain free.
        if ($inner === '' || $inner === '.') {
            return '';
        }

        return $suffix . '/' . $inner;
    }

    private function sendProtectionNotification(string $path, string $action): void {
        try {
            // Rate limiting: verifica se já notificou recentemente
            if (!$this->protectionChecker->shouldNotify($path, $action)) {
                return;
            }

            $userSession = \OCP\Server::get(\OCP\IUserSession::class);
            if (!$userSession || !$userSession->isLoggedIn()) {
                return;
            }
            $user = $userSession->getUser();
            if (!$user) {
                return;
            }

            $manager = \OCP\Server::get(\OCP\Notification\IManager::class);
            $notification = $manager->createNotification();

            $notification->setApp('folder_protection')
                ->setUser($user->getUID())
                ->setDateTime(new \DateTime())
                ->setObject('folder', substr(md5($path), 0, 32))
                ->setSubject('folder_protected', [
                    'path' => basename($path),
                    'action' => $action
                ]);

            $manager->notify($notification);
        } catch (\Throwable $e) {
            \OCP\Server::get(LoggerInterface::class)->error('FolderProtection: Failed to send notification: ' . $e->getMessage());
        }
    }

    public function __call($method, $args) {
        return call_user_func_array([$this->storage, $method], $args);
    }

    // Explicitly implement trash-control methods to avoid __call forwarding
    // these to Local (which has no such methods) and crashing with a TypeError.
    // We propagate down the chain via try-catch so intermediate Wrapper layers
    // (which forward via __call) are traversed, but the error is silently
    // swallowed when the bottom of the chain (Local) doesn't implement them.
    public function disableTrash(): void {
        try {
            $this->storage->disableTrash();
        } catch (\Throwable $e) {
            // Local storage has no trash concept — ignore silently.
        }
    }

    public function enableTrash(): void {
        try {
            $this->storage->enableTrash();
        } catch (\Throwable $e) {
            // Local storage has no trash concept — ignore silently.
        }
    }

    public function is_dir($path): bool {
        return $this->storage->is_dir($path);
    }

    public function isDeletable($path): bool {
        if ($this->protectionChecker->isProtected($this->buildCheckPath($path))) {
            return false;
        }
        return $this->storage->isDeletable($path);
    }

    public function isUpdatable($path): bool {
        if ($this->protectionChecker->isProtected($this->buildCheckPath($path))) {
            return false;
        }
        return $this->storage->isUpdatable($path);
    }

    public function copy($source, $target): bool {
        if ($this->protectionChecker->isProtectedOrParentProtected($this->buildCheckPath($source))) {
            $this->sendProtectionNotification($source, 'copy');
            throw new FolderLocked('This folder is protected and cannot be copied.', false);
        }
        return $this->storage->copy($source, $target);
    }

    public function rename(string $source, string $target): bool {
        if ($this->protectionChecker->isProtectedOrParentProtected($this->buildCheckPath($source))) {
            \OCP\Server::get(LoggerInterface::class)->warning("FolderProtection: blocked rename/move of protected folder: $source");
            $this->sendProtectionNotification($source, 'move');
            throw new FolderLocked("Moving protected folders is not allowed");
        }
        if ($this->protectionChecker->isProtected($this->buildCheckPath($target))) {
            \OCP\Server::get(LoggerInterface::class)->warning("FolderProtection: blocked rename to protected path: $target");
            throw new FolderLocked("Cannot move or rename to a protected folder path");
        }
        return $this->storage->rename($source, $target);
    }

    public function unlink(string $path): bool {
        if ($this->protectionChecker->isProtected($this->buildCheckPath($path))) {
            $this->sendProtectionNotification($path, 'delete');
            throw new FolderLocked('This folder is protected and cannot be deleted.');
        }
        return $this->storage->unlink($path);
    }

    public function copyFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        if (!empty($sourceInternalPath) && $this->protectionChecker->isProtectedOrParentProtected($sourceInternalPath)) {
            $this->sendProtectionNotification($sourceInternalPath, 'copy');
            throw new FolderLocked('This folder is protected and cannot be copied.', false);
        }

        if (method_exists($sourceStorage, 'getFolderId')) {
            $folderId = $sourceStorage->getFolderId();
            $groupFolderPath = "/__groupfolders/$folderId";
            if ($this->protectionChecker->isProtectedOrParentProtected($groupFolderPath)) {
                $this->sendProtectionNotification($groupFolderPath, 'copy');
                throw new FolderLocked('This group folder is protected and cannot be copied.', false);
            }
        }

        if ($this->protectionChecker->isProtected($targetInternalPath)) {
            throw new FolderLocked('Cannot copy into protected folders.', false);
        }

        return parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    public function moveFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        if ($this->protectionChecker->isProtectedOrParentProtected($sourceInternalPath)) {
            $this->sendProtectionNotification($sourceInternalPath, 'move');
            throw new FolderLocked('This folder is protected and cannot be moved.', false);
        }
        if ($this->protectionChecker->isProtected($targetInternalPath)) {
            throw new FolderLocked('Cannot move to a protected folder path.', false);
        }
        return parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    public function rmdir(string $path): bool {
        if ($this->protectionChecker->isProtected($this->buildCheckPath($path))) {
            $this->sendProtectionNotification($path, 'delete');
            throw new FolderLocked('This folder is protected and cannot be removed.');
        }
        return $this->storage->rmdir($path);
    }

    public function getPermissions($path): int {
        if ($this->protectionChecker->isProtected($this->buildCheckPath($path))) {
            return \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_SHARE;
        }
        return $this->storage->getPermissions($path);
    }

    public function file_exists($path): bool {
        return $this->storage->file_exists($path);
    }

    public function mkdir(string $path): bool {
        if ($this->protectionChecker->isProtected($this->buildCheckPath($path))) {
            $this->sendProtectionNotification($path, 'create');
            throw new FolderLocked('Cannot create directory: target is protected or inside a protected folder.', false);
        }
        return $this->storage->mkdir($path);
    }
}
