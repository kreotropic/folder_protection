<?php
declare(strict_types=1);

namespace OCA\FolderProtection\DAV;

use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\INode;
use Psr\Log\LoggerInterface;

/**
 * Plugin que adiciona propriedades DAV customizadas para folders protegidos
 */
class ProtectionPropertyPlugin extends ServerPlugin {
    
    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;
    private ?\Sabre\DAV\Server $server = null;

    // Namespace e propriedades customizadas
    const NS_NEXTCLOUD = 'http://nextcloud.org/ns';
    const PROP_IS_PROTECTED = '{http://nextcloud.org/ns}is-protected';
    const PROP_PROTECTION_REASON = '{http://nextcloud.org/ns}protection-reason';
    const PROP_IS_DELETABLE = '{http://nextcloud.org/ns}is-deletable';
    const PROP_IS_RENAMEABLE = '{http://nextcloud.org/ns}is-renameable';
    const PROP_IS_MOVEABLE = '{http://nextcloud.org/ns}is-moveable';

    // Propriedade OwnCloud de permissões — lida pelo cliente desktop para decidir operações
    const PROP_OC_PERMISSIONS = '{http://owncloud.org/ns}permissions';

    public function __construct(
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger
    ) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

    /**
     * Inicializar plugin no servidor DAV
     */
    public function initialize(\Sabre\DAV\Server $server): void {
        $this->server = $server;
        // Prioridade 150: corre DEPOIS do FilesPlugin do core (prioridade 100 default)
        // para podermos ler e sobrepor oc:permissions já calculado pelo core
        $server->on('propFind', [$this, 'propFind'], 150);

        $this->logger->debug('FolderProtection: ProtectionPropertyPlugin initialized');
    }

    /**
     * Handler para PROPFIND - adiciona propriedades customizadas
     */
    public function propFind(PropFind $propFind, INode $node): void {
        // Só aplicar a ficheiros e diretórios do Nextcloud
        if (!($node instanceof \OCA\DAV\Connector\Sabre\Directory) &&
            !($node instanceof \OCA\DAV\Connector\Sabre\File)) {
            return;
        }

        $candidates = $this->getNodePathCandidates($node);
        if (empty($candidates)) {
            return;
        }

        // Check protection against all path format candidates
        $isProtected    = false;
        $protectedPath  = '';
        $protectionInfo = null;
        foreach ($candidates as $candidate) {
            if ($this->protectionChecker->isProtected($candidate)) {
                $isProtected    = true;
                $protectedPath  = $candidate;
                $protectionInfo = $this->protectionChecker->getProtectionInfo($candidate);
                break;
            }
        }
        $path = $candidates[0]; // primary path for logging

        $this->logger->debug("FolderProtection PROPFIND: path='$path', protected=" . ($isProtected ? 'yes' : 'no'));

        // 1. Flag: está protegido?
        $propFind->handle(self::PROP_IS_PROTECTED, function() use ($isProtected) {
            return $isProtected ? 'true' : 'false';
        });

        // 2. Razão da proteção
        $propFind->handle(self::PROP_PROTECTION_REASON, function() use ($protectionInfo) {
            return $protectionInfo['reason'] ?? '';
        });

        // 3. Meta-propriedades para controlar UI do cliente
        $propFind->handle(self::PROP_IS_DELETABLE, function() use ($isProtected) {
            return $isProtected ? 'false' : 'true';
        });

        $propFind->handle(self::PROP_IS_RENAMEABLE, function() use ($isProtected) {
            return $isProtected ? 'false' : 'true';
        });

        $propFind->handle(self::PROP_IS_MOVEABLE, function() use ($isProtected) {
            return $isProtected ? 'false' : 'true';
        });

        // 4. Remover 'D' (delete) de oc:permissions para pastas protegidas.
        //
        // Raciocínio: sem 'D', o cliente desktop sabe que não pode apagar/mover a pasta
        // e não tenta fazê-lo. Desta forma a pasta nunca desaparece localmente.
        //
        // Comportamento equivalente ao das group folders: o ACLStorageWrapper do app groupfolders
        // remove o bit DELETE de getPermissions() quando ACL o proíbe, e o cliente desktop trata
        // a pasta como não-eliminável — nunca envia DELETE, nunca marca como "sync error",
        // a pasta mantém-se visível.
        //
        // Sem esta remoção, o cliente vê 'D', tenta DELETE → recebe 403 do ProtectionPlugin →
        // marca o item como "sync error" permanente → pasta desaparece localmente e não volta.
        //
        // A protecção real (bloquear DELETE/MOVE/COPY mesmo que o cliente tente) continua
        // garantida pelo ProtectionPlugin em beforeUnbind/beforeMove/beforeBind.
        if ($isProtected) {
            // PropFind::get() force-avalia o lazy callback registado pelo FilesPlugin (prioridade 100)
            // e devolve a string de permissões actual (ex: "RGDNVCK").
            // PropFind::set() substitui o valor para todos os clientes que peçam esta propriedade.
            $currentPerms = $propFind->get(self::PROP_OC_PERMISSIONS);
            if (is_string($currentPerms) && $currentPerms !== '') {
                $propFind->set(self::PROP_OC_PERMISSIONS, str_replace('D', '', $currentPerms));
                $this->logger->debug("FolderProtection PROPFIND: stripped 'D' from oc:permissions for '$path' (was: '$currentPerms')");
            }
        }
    }

    /**
     * Extrair path interno do node, com suporte a group folders.
     *
     * @param INode  $node  DAV node being inspected
     * @param string $uri   Path of the node relative to the DAV tree root (e.g. 'files/ncadmin/folder').
     *                      Provided by PropFind::getPath() — already in the correct format for regular nodes.
     */
    /**
     * Returns all path candidates for a node.
     * Checks both mount-point format ('/files/team/sub') and group folder ID format
     * ('/__groupfolders/1/sub') to match any DB storage format.
     */
    private function getNodePathCandidates(INode $node): array {
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
            $this->logger->error('FolderProtection: Error getting node path', [
                'exception' => $e->getMessage()
            ]);
            return [];
        }
    }

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
     * Nome do plugin
     */
    public function getPluginName(): string {
        return 'folder-protection-properties';
    }

    /**
     * Features suportadas
     */
    public function getFeatures(): array {
        return ['folder-protection'];
    }
}