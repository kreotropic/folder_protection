// Nota: logs do módulo são condicionados por `config.debug` (padrão: false).

/**
 * Folder Protection UI Module for Nextcloud 32
 * Uses CSS pseudo-elements for badges (Vue-compatible)
 * 
 * v2.2.0 - Fix: lock icon appearing on wrong rows after navigation
 *   - Detect directory changes via hashchange to force full reprocess
 *   - Always reprocess all visible rows (virtual scrolling recycles DOM elements)
 * v2.3.0 - Performance: near-instant lock icon rendering
 *   - Single RAF debounce (removed double RAF+setTimeout)
 *   - processRow only touches DOM when state actually changes
 *   - currentDir computed once per cycle, not per row
 *   - Synchronous processing (safe: virtual scroll = ~30-100 DOM rows max)
 */

(function() {
    'use strict';

    const FolderProtectionUI = {
        config: {
            apiEndpoint: '/apps/folder_protection/api/status',
            protectedAttr: 'data-folder-protected',
            protectedClass: 'fp-protected',
            checkInterval: 100,
            maxCheckAttempts: 50,
            debug: false
        },

        state: {
            protectedFolders: new Set(),
            normalizedProtected: new Set(),
            initialized: false,
            observer: null,
            currentDir: null  // Track current directory to detect navigation
        },

        log(...args) {
            if (this.config.debug) console.log(...args);
        },

        error(...args) {
            if (this.config.debug) console.error(...args);
        },

        async init() {
            this.log('[FolderProtection] Initializing');
            
            await this.loadProtectedFolders();
            this.injectStyles();
            
            this.waitForFilesApp().then(() => {
                this.state.currentDir = this.getCurrentDirectory();
                this.setupEventListeners();
                this.markProtectedFolders();
                this.state.initialized = true;
                this.log('[FolderProtection] ✅ Initialized');
            });
        },

        async loadProtectedFolders() {
            try {
                const response = await fetch(OC.generateUrl(this.config.apiEndpoint));
                const data = await response.json();
                
                if (data.success && data.protections) {
                    this.state.protectedFolders = new Set(Object.keys(data.protections));
                    this.preprocessProtectedFolders();
                    this.log('[FolderProtection] Loaded', this.state.protectedFolders.size, 'folders');
                }
            } catch (error) {
                this.error('[FolderProtection] Load failed:', error);
            }
        },

        normalizePath(p) {
            if (!p) return p;
            p = p.replace(/\/+/g, '/');
            if (!p.startsWith('/')) p = '/' + p;
            if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
            return p;
        },

        preprocessProtectedFolders() {
            const normalized = new Set();
            for (const p of this.state.protectedFolders) {
                if (!p) continue;
                normalized.add(this.normalizePath(p));
            }
            this.state.normalizedProtected = normalized;
        },

        injectStyles() {
            if (document.getElementById('folder-protection-styles')) return;

            const styles = `
                :root {
                    --protection-badge-color: rgba(0, 0, 0, 0.9);
                    --protection-badge-text-color: #fff;
                    --protection-badge-zindex: 1000;
                    --protection-badge-shadow: 0 1px 3px rgba(0,0,0,0.5);
                    --protection-badge-size: 14px;
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon {
                    position: relative !important;
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon::after {
                    content: '🔒';
                    position: absolute;
                    bottom: 15px;
                    right: -9px;
                    font-size: var(--protection-badge-size);
                    line-height: 16px;
                    z-index: calc(var(--protection-badge-zindex) - 990);
                    filter: drop-shadow(var(--protection-badge-shadow));
                    pointer-events: none;
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-name::after {
                    content: 'Protected folder';
                    position: absolute;
                    top: -20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: var(--protection-badge-color);
                    color: var(--protection-badge-text-color);
                    padding: 6px 10px;
                    border-radius: 4px;
                    font-size: 12px;
                    white-space: nowrap;
                    opacity: 0;
                    pointer-events: none;
                    transition: opacity 0.2s;
                    z-index: var(--protection-badge-zindex);
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-name:hover::after {
                    opacity: 1;
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon-overlay {
                    z-index: calc(var(--protection-badge-zindex) + 1);
                }

                @keyframes badgeAppear {
                    from { opacity: 0; transform: scale(0); }
                    to { opacity: 1; transform: scale(1); }
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon::after {
                    animation: badgeAppear 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                }

                .files-list__row.${this.config.protectedClass} [data-cy-files-list-row-action="copy"],
                .files-list__row.${this.config.protectedClass} [data-action="copy"] {
                    display: none !important;
                }
            `;

            const styleEl = document.createElement('style');
            styleEl.id = 'folder-protection-styles';
            styleEl.textContent = styles;
            document.head.appendChild(styleEl);
        },

        waitForFilesApp() {
            return new Promise((resolve) => {
                let attempts = 0;
                const check = setInterval(() => {
                    const app = document.querySelector('.files-list__tbody');
                    if (app || ++attempts > this.config.maxCheckAttempts) {
                        clearInterval(check);
                        resolve();
                    }
                }, this.config.checkInterval);
            });
        },

        /**
         * Clear all protection markers from every row.
         * Called on directory change to ensure recycled DOM elements
         * don't carry stale lock icons into the new listing.
         */
        clearAllMarkers() {
            document.querySelectorAll(`.files-list__row.${this.config.protectedClass}`).forEach(row => {
                row.classList.remove(this.config.protectedClass);
            });
            this.log('[FolderProtection] 🧹 Cleared all markers');
        },

        /**
         * Detect directory change and force full reprocess.
         * The Nextcloud file list reuses DOM elements (virtual scrolling),
         * so navigating into/out of a folder recycles <tr> elements that
         * may still carry fp-protected / fp-processed classes from the
         * previous directory listing.
         */
        onDirectoryChanged() {
            const newDir = this.getCurrentDirectory();
            if (newDir !== this.state.currentDir) {
                this.log('[FolderProtection] 📂 Directory changed:', this.state.currentDir, '→', newDir);
                this.state.currentDir = newDir;
                this.clearAllMarkers();
                // Use RAF instead of fixed delay — processes as soon as
                // the browser has painted the new rows (~16ms max)
                requestAnimationFrame(() => this.markProtectedFolders());
            }
        },

        setupEventListeners() {
            this.log('[FolderProtection] Setting up observer');

            // --- Directory change detection ---
            // hashchange covers back/forward navigation and breadcrumb clicks
            window.addEventListener('hashchange', () => this.onDirectoryChanged());

            // popstate covers browser back/forward buttons
            window.addEventListener('popstate', () => {
                // Small delay: hash may not be updated yet at popstate time
                setTimeout(() => this.onDirectoryChanged(), 10);
            });

            this.setupActionMenuObserver();
            this.setupSelectionObserver();

            const container = document.querySelector('#app-content-vue');
            if (!container) return;

            let rafId = null;
            
            const scheduleProcess = () => {
                // Single RAF — fires on next paint frame (~16ms max).
                // No extra setTimeout needed; processRow is lightweight
                // (just classList ops on visible rows).
                if (rafId) return; // Already scheduled for this frame
                
                rafId = requestAnimationFrame(() => {
                    rafId = null;
                    this.log('[FolderProtection] ⚡ Processing rows');
                    this.markProtectedFolders();
                });
            };
            
            this.state.observer = new MutationObserver(() => {
                const tbody = document.querySelector('tbody.files-list__tbody');
                const hasRows = tbody?.querySelectorAll('tr.files-list__row').length > 0;
                
                if (hasRows) {
                    scheduleProcess();
                }
            });

            this.state.observer.observe(container, {
                childList: true,
                subtree: true
            });
            
            this.log('[FolderProtection] ✅ Observer active');
        },

        setupSelectionObserver() {
            const container = document.querySelector('#app-content-vue') || document.body;

            const observer = new MutationObserver(() => {
                this.updateSelectionBarCopyButton();
            });

            observer.observe(container, {
                subtree: true,
                attributeFilter: ['aria-selected', 'data-cy-files-list-row-selected', 'class'],
                childList: true,
            });
        },

        updateSelectionBarCopyButton() {
            const anyProtectedSelected = document.querySelector(
                `.files-list__row.${this.config.protectedClass} input[type="checkbox"]:checked, ` +
                `.files-list__row.${this.config.protectedClass}[data-cy-files-list-row-selected="true"], ` +
                `.files-list__row.${this.config.protectedClass}.selected`
            );

            const copyBtn = document.querySelector('[data-cy-files-list-selection-action="move-copy"]');
            if (copyBtn) {
                copyBtn.style.display = anyProtectedSelected ? 'none' : '';
                this.log('[FolderProtection] Selection bar Copy button:', anyProtectedSelected ? 'hidden' : 'visible');
            }
        },

        setupActionMenuObserver() {
            const bodyObserver = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    for (const node of mutation.addedNodes) {
                        if (!(node instanceof HTMLElement)) continue;

                        const menu = node.matches('[data-cy-files-action-menu], [role="menu"]')
                            ? node
                            : node.querySelector('[data-cy-files-action-menu], [role="menu"]');

                        if (menu) {
                            this.hideCopyInMenu(menu);
                        }
                    }
                }
            });

            bodyObserver.observe(document.body, { childList: true, subtree: true });
        },

        hideCopyInMenu(menu) {
            const activeRow = document.querySelector(
                `.files-list__row.${this.config.protectedClass}:hover, ` +
                `.files-list__row.${this.config.protectedClass}[data-cy-files-list-row-selected="true"]`
            );

            if (!activeRow) return;

            const selectors = [
                '[data-cy-files-list-row-action="copy"]',
                '[data-action="copy"]',
                'button[aria-label*="Copy"], button[aria-label*="Copiar"]',
                'li:has(button[aria-label*="Copy"]), li:has(button[aria-label*="Copiar"])',
            ];

            for (const sel of selectors) {
                try {
                    menu.querySelectorAll(sel).forEach(el => {
                        el.closest('li') ? el.closest('li').remove() : el.remove();
                        this.log('[FolderProtection] Removed copy action from menu');
                    });
                } catch (_) { /* :has() may not be supported */ }
            }
        },

        /**
         * Process a single row: always clean and re-evaluate.
         * This is critical because Nextcloud's virtual scrolling recycles
         * DOM elements — a <tr> that was "Documentos" (protected) may now
         * represent "Fotos" (not protected) with the same DOM node.
         * 
         * @param {HTMLElement} row - The table row element
         * @param {string} currentDir - Pre-computed current directory (avoid repeated DOM reads)
         */
        processRow(row, currentDir) {
            const filename = row.getAttribute('data-cy-files-list-row-name');
            if (!filename) return;

            const fullPath = this.buildFullPath(currentDir, filename);
            const isProtected = this.isFolderProtected(fullPath);
            const wasProtected = row.classList.contains(this.config.protectedClass);

            // Only touch DOM if state actually changed
            if (isProtected && !wasProtected) {
                row.classList.add(this.config.protectedClass);
            } else if (!isProtected && wasProtected) {
                row.classList.remove(this.config.protectedClass);
            }
        },

        /**
         * Mark protected folders in the current listing.
         * 
         * Processes ALL visible rows synchronously. This is safe because:
         * - Virtual scrolling means only ~30-100 rows exist in DOM at once
         * - processRow only touches DOM when state changes (cheap no-op otherwise)
         * - currentDir is computed once and reused across all rows
         */
        markProtectedFolders() {
            const allRows = document.querySelectorAll('.files-list__row');
            
            if (allRows.length === 0) {
                this.log('[FolderProtection] No rows to process');
                return;
            }

            this.log(`[FolderProtection] Processing ${allRows.length} rows`);
            
            // Compute once, reuse for all rows (avoids repeated hash parsing)
            const currentDir = this.getCurrentDirectory();
            
            for (let i = 0; i < allRows.length; i++) {
                this.processRow(allRows[i], currentDir);
            }
        },

        getCurrentDirectory() {
            // Nextcloud 32+: dir is in the query string (?dir=/path)
            const searchParams = new URLSearchParams(window.location.search);
            if (searchParams.has('dir')) {
                return searchParams.get('dir');
            }
            // Older Nextcloud: dir was in the hash (#dir=/path)
            const hash = window.location.hash;
            const match = hash.match(/dir=([^&]*)/);
            return match ? decodeURIComponent(match[1]) : '/';
        },

        buildFullPath(currentDir, filename) {
            currentDir = currentDir.replace(/^\/files/, '');
            let fullPath = currentDir === '/' ? `/${filename}` : `${currentDir}/${filename}`;
            fullPath = `/files${fullPath}`.replace(/\/+/g, '/');
            return fullPath;
        },

        isFolderProtected(fullPath) {
            if (!fullPath) return false;

            const np = this.normalizePath(fullPath);
            if (this.state.normalizedProtected?.has(np)) return true;

            // Show lock on ancestor folders that contain a protected descendant
            const prefix = np.endsWith('/') ? np : np + '/';
            for (const protectedPath of this.state.normalizedProtected) {
                if (protectedPath.startsWith(prefix)) return true;
            }

            return false;
        },

        async refresh() {
            await this.loadProtectedFolders();
            this.clearAllMarkers();
            this.markProtectedFolders();
        },

        destroy() {
            if (this.state.observer) this.state.observer.disconnect();
            document.getElementById('folder-protection-styles')?.remove();
            this.clearAllMarkers();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FolderProtectionUI.init());
    } else {
        setTimeout(() => FolderProtectionUI.init(), 100);
    }

    window.FolderProtectionUI = FolderProtectionUI;

})();