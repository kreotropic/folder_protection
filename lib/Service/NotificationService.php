<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Service;

use OCA\FolderProtection\ProtectionChecker;
use OCP\IUserSession;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

class NotificationService {

    public function __construct(
        private ProtectionChecker $protectionChecker,
        private IUserSession $userSession,
        private IManager $notificationManager,
        private LoggerInterface $logger,
    ) {}

    public function notifyBlocked(string $path, string $action): void {
        try {
            if (!$this->protectionChecker->shouldNotify($path, $action)) {
                return;
            }

            if (!$this->userSession->isLoggedIn()) {
                return;
            }
            $user = $this->userSession->getUser();
            if ($user === null) {
                return;
            }

            $notification = $this->notificationManager->createNotification();
            $notification->setApp('folder_protection')
                ->setUser($user->getUID())
                ->setDateTime(new \DateTime())
                ->setObject('folder', md5($path))
                ->setSubject('folder_protected', [
                    'path'   => basename($path),
                    'action' => $action,
                ]);

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection: Failed to send notification: ' . $e->getMessage());
        }
    }
}
