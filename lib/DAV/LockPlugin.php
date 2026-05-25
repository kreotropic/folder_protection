<?php
declare(strict_types=1);

namespace OCA\FolderProtection\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Locks\LockInfo;
use Sabre\DAV\Xml\Response\MultiStatus;
use Psr\Log\LoggerInterface;

/**
 * Plugin WebDAV que implementa bloqueio de lock/unlock para pastas protegidas.
 * 
 * Estratégia:
 * 1. Bloqueia tentativas de LOCK em pastas protegidas (com exceção 403)
 * 2. Bloqueia tentativas de UNLOCK em pastas protegidas (com exceção 403)
 * 3. Reporta propriedades customizadas que indicam proteção 
 * 4. Usa o namespace Nextcloud para máxima compatibilidade
 * 
 * Windows, macOS e clientes WebDAV:
 * - Veem que a pasta está protegida
 * - Recebem 403 se tentarem lock/unlock
 * - Sem necessidade de restaurar a pasta depois
 */
class LockPlugin extends ServerPlugin {

    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;
    private ?Server $server = null;

    public function __construct(
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger
    ) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

    public function initialize(Server $server): void {
        $this->server = $server;
        
        // Handlers para interceptar operações de lock
        $server->on('beforeLock', [$this, 'beforeLock'], 5);   // Prioridade alta - antes do ProtectionPlugin
        $server->on('beforeUnlock', [$this, 'beforeUnlock'], 5);
        
        // Handler para PROPFIND - reportar locks nas propriedades
        $server->on('propFind', [$this, 'propFind']);
        
        $this->logger->info('FolderProtection: Lock plugin initialized');
    }

    /**
     * Intercepta tentativas de LOCK - bloqueia locks em pastas protegidas
     */
    public function beforeLock(string $uri, LockInfo $lock): void {
        try {
            foreach ($this->getInternalPathCandidates($uri) as $path) {
                if ($this->protectionChecker->isProtected($path)) {
                    $info   = $this->protectionChecker->getProtectionInfo($path);
                    $reason = $info['reason'] ?? 'Protected by server policy';
                    $this->logger->warning("FolderProtection Lock: Blocking LOCK attempt on protected: $path");
                    $this->setResponseHeaders('lock', $reason);
                    throw new \Sabre\DAV\Exception\Forbidden(sprintf('🛡️ FOLDER PROTECTED: %s', $reason));
                }
            }
        } catch (\Sabre\DAV\Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection Lock: Error in beforeLock', ['error' => $e->getMessage()]);
            throw new \Sabre\DAV\Exception\InternalServerError('Protection check failed');
        }
    }

    public function beforeUnlock(string $uri, LockInfo $lock): void {
        try {
            foreach ($this->getInternalPathCandidates($uri) as $path) {
                if ($this->protectionChecker->isProtected($path)) {
                    $this->logger->warning("FolderProtection Lock: Blocking UNLOCK attempt on protected: $path");
                    throw new \Sabre\DAV\Exception\Forbidden('Cannot unlock protected folders. They are locked by the system.');
                }
            }
        } catch (\Sabre\DAV\Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection Lock: Error in beforeUnlock', ['error' => $e->getMessage()]);
            throw new \Sabre\DAV\Exception\InternalServerError('Protection check failed');
        }
    }

    public function propFind(PropFind $propFind, \Sabre\DAV\INode $node): void {
        try {
            $candidates = $this->getNodePathCandidates($node);
            if (empty($candidates)) {
                return;
            }

            $protectedPath = '';
            $info          = null;
            foreach ($candidates as $path) {
                if ($this->protectionChecker->isProtected($path)) {
                    $protectedPath = $path;
                    $info          = $this->protectionChecker->getProtectionInfo($path);
                    break;
                }
            }
            if ($protectedPath === '') {
                return;
            }

            $reason = $info['reason'] ?? 'Protected by server policy';
            $propFind->handle('{http://nextcloud.org/ns}is-locked', 'true');
            $propFind->handle('{http://nextcloud.org/ns}lock-reason', $reason);
            $this->logger->debug("FolderProtection Lock: Reported lock status for: $protectedPath");
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection Lock: Error in propFind', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Returns all path candidates for a node.
     * Checks both mount-point format ('/files/team/sub') and group folder ID format
     * ('/__groupfolders/1/sub') to match any DB storage format.
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
                $base = (strpos($internalPath, 'files/') !== 0)
                    ? 'files/' . ltrim($internalPath, '/')
                    : $internalPath;
                $candidates[] = '/' . $base;
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

            return $candidates;
        } catch (\Exception $e) {
            $this->logger->error('FolderProtection Lock: Error getting node path', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Traverses the storage wrapper chain to find a GroupFolder storage with getFolderId().
     */
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
     * Resolves a URI to all path candidates (both formats), with fallback.
     */
    private function getInternalPathCandidates(string $uri): array {
        try {
            if ($this->server) {
                $node = $this->server->tree->getNodeForPath($uri);
                $candidates = $this->getNodePathCandidates($node);
                if (!empty($candidates)) {
                    return $candidates;
                }
            }
        } catch (\Exception $e) {
            // node not found — fallback below
        }
        return [urldecode($uri)];
    }

    /**
     * Define headers HTTP para resposta
     */
    private function setResponseHeaders(string $action, string $reason): void {
        if ($this->server) {
            $this->server->httpResponse->setHeader('X-NC-Folder-Protected', 'true');
            $this->server->httpResponse->setHeader('X-NC-Protection-Action', $action);
            $this->server->httpResponse->setHeader('X-NC-Protection-Reason', $reason);
        }
    }

    public function getPluginName(): string {
        return 'folder-protection-lock';
    }

    public function getFeatures(): array {
        return ['folder-protection-lock'];
    }
}
