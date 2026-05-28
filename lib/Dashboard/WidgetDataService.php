<?php

declare(strict_types=1);

namespace OCA\FolderProtection\Dashboard;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Serviço de dados para o widget do dashboard.
 *
 * Obtém as pastas protegidas e os seus tamanhos consultando
 * diretamente a tabela filecache (mais eficiente que IRootFolder).
 */
class WidgetDataService {

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger
    ) {}

    /**
     * Devolve a lista de pastas protegidas com tamanho, para o widget.
     *
     * @param int $limit Número máximo de entradas
     * @return array<int, array{id: int, path: string, display_name: string, reason: string|null, created_by: string|null, created_at: int, size: int, is_group_folder: bool, group_folder_id: int|null}>
     */
    public function getProtectedFolders(int $limit = 10): array {
        // 1. Buscar pastas protegidas
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'path', 'file_id', 'user_id', 'reason', 'created_by', 'created_at')
           ->from('folder_protection')
           ->orderBy('created_at', 'DESC')
           ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $rows[] = $row;
        }
        $result->closeCursor();

        // 2. Enriquecer cada linha com tamanho e nome display
        $folders = [];
        foreach ($rows as $row) {
            $path     = (string) $row['path'];
            $fileId   = $row['file_id'] !== null ? (int) $row['file_id'] : null;
            $userId   = $row['user_id'] ?: null;
            $isGroup  = str_starts_with($path, '/__groupfolders/');
            $groupId  = null;

            if ($isGroup) {
                // Extrai o ID numérico: /__groupfolders/3 → 3
                $parts   = explode('/', trim($path, '/'));
                $groupId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : null;
                $size    = $groupId !== null ? $this->getGroupFolderSize($groupId) : -1;
                $displayName = $this->getGroupFolderName($groupId) ?? $path;
            } elseif ($fileId !== null) {
                $size        = $this->getSizeByFileId($fileId);
                $displayName = $this->getDisplayName($path);
            } else {
                $size        = $this->getSizeByPath($path, $userId);
                $displayName = $this->getDisplayName($path);
            }

            $folders[] = [
                'id'              => (int) $row['id'],
                'path'            => $path,
                'display_name'    => $displayName,
                'reason'          => $row['reason'] ?: null,
                'created_by'      => $row['created_by'] ?: null,
                'created_at'      => (int) $row['created_at'],
                'size'            => $size,
                'is_group_folder' => $isGroup,
                'group_folder_id' => $groupId,
            ];
        }

        return $folders;
    }

    /**
     * Obtém o tamanho de uma pasta a partir do file_id via oc_filecache.
     */
    private function getSizeByFileId(int $fileId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('size')
               ->from('filecache')
               ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));

            $result = $qb->executeQuery();
            $row    = $result->fetchAssociative();
            $result->closeCursor();

            return $row ? (int) $row['size'] : -1;
        } catch (\Throwable $e) {
            $this->logger->warning('FolderProtection widget: failed to get size by file_id', [
                'file_id'   => $fileId,
                'exception' => $e->getMessage(),
            ]);
            return -1;
        }
    }

    /**
     * Obtém o tamanho de um group folder via oc_storages + oc_filecache.
     *
     * O storage do group folder tem id no formato:
     *   local::/path/to/data/__groupfolders/{id}/
     *
     * Usamos LIKE '%__groupfolders/{id}/%' para ser agnóstico ao caminho base.
     */
    private function getGroupFolderSize(int $groupFolderId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fc.size')
               ->from('filecache', 'fc')
               ->join('fc', 'storages', 's', $qb->expr()->eq('fc.storage', 's.numeric_id'))
               ->where($qb->expr()->like(
                   's.id',
                   $qb->createNamedParameter('%__groupfolders/' . $groupFolderId . '/%')
               ))
               ->andWhere($qb->expr()->eq('fc.path', $qb->createNamedParameter('')))
               ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetchAssociative();
            $result->closeCursor();

            // size = 0 é válido (pasta vazia); -1 = não encontrado
            return $row ? (int) $row['size'] : -1;
        } catch (\Throwable $e) {
            $this->logger->warning('FolderProtection widget: failed to get group folder size', [
                'group_folder_id' => $groupFolderId,
                'exception'       => $e->getMessage(),
            ]);
            return -1;
        }
    }

    /**
     * Tenta obter o nome visível de um group folder.
     */
    private function getGroupFolderName(int $groupFolderId): ?string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('mount_point')
               ->from('group_folders')
               ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupFolderId)));

            $result = $qb->executeQuery();
            $row    = $result->fetchAssociative();
            $result->closeCursor();

            return $row ? (string) $row['mount_point'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Obtém o tamanho de uma pasta regular via user_id + path interno.
     * Usado quando file_id não está guardado na DB (protect() via UI).
     *
     * O path guardado na DB tem formato /files/{subfolder} (sem username).
     * O path interno no filecache é files/{subfolder}.
     * O storage do utilizador está em oc_storages como home::{user} ou object::user:{user}.
     */
    private function getSizeByPath(string $path, ?string $userId): int {
        if ($userId === null || !str_starts_with($path, '/files/')) {
            return -1;
        }

        try {
            $internalPath = ltrim($path, '/'); // /files/Template → files/Template

            // Obter o numeric_id do storage do utilizador
            $qb = $this->db->getQueryBuilder();
            $qb->select('numeric_id')
               ->from('storages')
               ->where($qb->expr()->orX(
                   $qb->expr()->eq('id', $qb->createNamedParameter('home::' . $userId)),
                   $qb->expr()->eq('id', $qb->createNamedParameter('object::user:' . $userId))
               ))
               ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetchAssociative();
            $result->closeCursor();

            if (!$row) {
                return -1;
            }
            $storageId = (int) $row['numeric_id'];

            // Obter o tamanho do filecache
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('size')
                ->from('filecache')
                ->where($qb2->expr()->eq('storage', $qb2->createNamedParameter($storageId)))
                ->andWhere($qb2->expr()->eq('path', $qb2->createNamedParameter($internalPath)))
                ->setMaxResults(1);

            $result2 = $qb2->executeQuery();
            $row2    = $result2->fetchAssociative();
            $result2->closeCursor();

            return $row2 ? (int) $row2['size'] : -1;
        } catch (\Throwable $e) {
            $this->logger->warning('FolderProtection widget: failed to get size by path', [
                'path'      => $path,
                'user_id'   => $userId,
                'exception' => $e->getMessage(),
            ]);
            return -1;
        }
    }

    /**
     * Extrai o nome de display a partir do path completo.
     * Ex: /files/admin/Documents/Finance → Finance
     */
    private function getDisplayName(string $path): string {
        $trimmed = rtrim($path, '/');
        $pos     = strrpos($trimmed, '/');
        return $pos !== false ? substr($trimmed, $pos + 1) : $trimmed;
    }
}
