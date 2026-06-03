<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Tests\Unit;

use OCP\ICache;

/**
 * Simple in-memory ICache implementation for unit tests.
 * Avoids complex mocking of the distributed cache interface.
 */
class ArrayCache implements ICache {
    private array $store = [];

    public function get($key): mixed {
        return $this->store[$key] ?? null;
    }

    public function set($key, $value, $ttl = 0): bool {
        $this->store[$key] = $value;
        return true;
    }

    public function remove($key): bool {
        unset($this->store[$key]);
        return true;
    }

    public function clear($prefix = ''): bool {
        if ($prefix === '') {
            $this->store = [];
        } else {
            foreach (array_keys($this->store) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->store[$key]);
                }
            }
        }
        return true;
    }

    public function hasKey($key): bool {
        return array_key_exists($key, $this->store);
    }

    public static function isAvailable(): bool {
        return true;
    }
}
