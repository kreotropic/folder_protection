<?php

declare(strict_types=1);

namespace OCA\FolderProtection\Controller;

use OCA\FolderProtection\Dashboard\WidgetDataService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller para o widget do Dashboard.
 * Fornece os dados ao componente Vue via REST.
 */
class WidgetController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private WidgetDataService $dataService
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Endpoint chamado pelo componente Vue do widget.
     * Requer autenticação de admin (herdado do AdminRequired do grupo de rotas).
     */
    #[NoCSRFRequired]
    public function getData(): JSONResponse {
        try {
            $folders = $this->dataService->getProtectedFolders(20);
            return new JSONResponse(['folders' => $folders]);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => 'Failed to load widget data: ' . $e->getMessage()],
                \OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
