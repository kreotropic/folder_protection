<?php

declare(strict_types=1);

namespace OCA\FolderProtection\Dashboard;

use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Widget do Dashboard: mostra as pastas protegidas e os seus tamanhos.
 *
 * Implementa IAPIWidget para que o Nextcloud Dashboard possa obter
 * os dados via API interna e renderizá-los via o componente Vue do widget.
 */
class ProtectedFoldersWidget implements IAPIWidget {

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private WidgetDataService $dataService
    ) {}

    public function getId(): string {
        return 'folder_protection';
    }

    public function getTitle(): string {
        return $this->l10n->t('Protected Folders');
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-password'; // ícone de cadeado disponível no Nextcloud
    }

    public function getUrl(): ?string {
        // Aponta para a página de administração da app
        return $this->urlGenerator->linkToRouteAbsolute(
            'settings.AdminSettings.index',
            ['section' => 'folder_protection']
        );
    }

    /**
     * Sem componente Vue personalizado — usamos a renderização padrão
     * do Nextcloud com os items de getItems().
     */
    public function load(): void {
        \OCP\Util::addScript('folder_protection', 'dashboard');
    }

    /**
     * Devolve os items do widget para a API interna do Dashboard.
     * Usado pelo dashboard poller (polling a cada ~30s).
     *
     * @param string      $userId ID do utilizador (não usado — widget é global para admins)
     * @param string|null $since  Timestamp ISO da última atualização (ignorado)
     * @param int         $limit  Número máximo de items
     * @return WidgetItem[]
     */
    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        $folders = $this->dataService->getProtectedFolders($limit);

        return array_map(function (array $folder) {
            $sizeStr   = $folder['size'] >= 0 ? $this->formatBytes($folder['size']) : '—';
            $subtitle  = $sizeStr;
            if ($folder['reason']) {
                $subtitle .= ' · ' . $folder['reason'];
            }

            return new WidgetItem(
                $folder['display_name'],                // título
                $subtitle,                              // subtítulo (tamanho + motivo)
                $this->urlGenerator->linkToRouteAbsolute(
                    'settings.AdminSettings.index',
                    ['section' => 'folder_protection']
                ),
                '',                                     // URL do ícone (vazio = usa ícone da app)
                (string) $folder['id']
            );
        }, $folders);
    }

    /**
     * Formata bytes em KB / MB / GB / TB de forma legível.
     */
    private function formatBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $i     = -1;
        do {
            $bytes /= 1024;
            $i++;
        } while ($bytes >= 1024 && $i < count($units) - 2);

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
