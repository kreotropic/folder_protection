// Nota: logs do módulo são condicionados por `config.debug` (padrão: false).

import './files-list-lock-badge.js'

/**
 * Folder Protection UI Module
 *
 * v2.2.0 - Fix: lock icon appearing on wrong rows after navigation
 * v2.3.0 - Performance: near-instant lock icon rendering
 * v2.3.1 - Hide delete action in context menu for protected folders
 * v2.3.3 - Bundled via webpack; uses generateUrl from @nextcloud/router
 *   instead of the deprecated OC.generateUrl global.
 * v2.5.0 - The lock badge itself is now rendered by files-list-lock-badge.js
 *   via the public @nextcloud/files plugin API (registerDavProperty +
 *   registerFileAction/renderInline) — the same mechanism core uses for the
 *   favorite star, driven by `nc:is-protected`/`nc:is-deletable` fetched in
 *   the listing's own PROPFIND. That removes the separate REST fetch, the
 *   directory-change/virtual-scroll bookkeeping, and the "row painted once
 *   without a lock, then patched a beat later" flash this module used to
 *   have. What remains here reacts to things with no declarative extension
 *   point in that API: hiding "copy"/"delete" from a row's context menu and
 *   from the selection bar. Both now detect "is this row protected" by
 *   checking for the rendered `.fp-lock-badge` element rather than a
 *   separately fetched/maintained Set of paths — one less thing that can
 *   drift out of sync with what's actually on screen.
 */

(function() {
    'use strict';

    const FolderProtectionUI = {
        config: {
            protectedRowSelector: '.files-list__row:has(.fp-lock-badge)',
            lockToggleKey: 'fp_show_locks',
            debug: false
        },

        log(...args) {
            if (this.config.debug) console.log(...args);
        },

        error(...args) {
            if (this.config.debug) console.error(...args);
        },

        init() {
            this.log('[FolderProtection] Initializing');

            const saved = localStorage.getItem(this.config.lockToggleKey);
            this.setLocksVisible(saved !== 'false');

            this.injectMenuStyles();
            this.setupActionMenuObserver();
            this.setupSelectionObserver();

            this.log('[FolderProtection] ✅ Initialized');
        },

        /**
         * Show/hide the lock badge rendered by files-list-lock-badge.js.
         * Pure CSS toggle (see body.fp-locks-hidden there) — takes effect
         * instantly on every already-rendered row, no re-render needed.
         * Deliberately independent from the menu/selection-bar restrictions
         * below: hiding the badge is a display preference, not a change in
         * what operations are actually blocked server-side.
         */
        setLocksVisible(visible) {
            document.body.classList.toggle('fp-locks-hidden', !visible);
            localStorage.setItem(this.config.lockToggleKey, visible);
        },

        injectMenuStyles() {
            if (document.getElementById('folder-protection-menu-styles')) return;

            const styles = `
                ${this.config.protectedRowSelector} [data-cy-files-list-row-action="copy"],
                ${this.config.protectedRowSelector} [data-action="copy"] {
                    display: none !important;
                }

                ${this.config.protectedRowSelector} [data-cy-files-list-row-action="delete"],
                ${this.config.protectedRowSelector} [data-action="delete"] {
                    display: none !important;
                }
            `;

            const styleEl = document.createElement('style');
            styleEl.id = 'folder-protection-menu-styles';
            styleEl.textContent = styles;
            document.head.appendChild(styleEl);
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
            let anyProtectedSelected = null;
            try {
                anyProtectedSelected = document.querySelector(
                    `${this.config.protectedRowSelector} input[type="checkbox"]:checked, ` +
                    `${this.config.protectedRowSelector}[data-cy-files-list-row-selected="true"], ` +
                    `${this.config.protectedRowSelector}.selected`
                );
            } catch (_) { /* :has() may not be supported */ }

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
                            // Delay para o Vue renderizar os <li> antes de os remover
                            setTimeout(() => this.hideBlockedActionsInMenu(menu), 50);
                        }
                    }
                }
            });

            bodyObserver.observe(document.body, { childList: true, subtree: true });
        },

        hideBlockedActionsInMenu(menu) {
            let activeRow = null;
            try {
                activeRow = document.querySelector(
                    `${this.config.protectedRowSelector}:hover, ` +
                    `${this.config.protectedRowSelector}[data-cy-files-list-row-selected="true"], ` +
                    `${this.config.protectedRowSelector}[aria-selected="true"], ` +
                    `${this.config.protectedRowSelector}:has(input[type="checkbox"]:checked)`
                );
            } catch (_) { /* :has() may not be supported */ }

            if (!activeRow) return;

            const selectors = [
                // Copy. The action id is "move-copy" — the same one the
                // selection bar hides in updateSelectionBarCopyButton().
                // "copy" never matched anything.
                '[data-cy-files-list-row-action="move-copy"]',
                '[data-cy-files-list-row-action="copy"]',
                '[data-action="copy"]',
                'button[aria-label*="Copy"], button[aria-label*="Copiar"]',
                'li:has(button[aria-label*="Copy"]), li:has(button[aria-label*="Copiar"])',
                // Delete
                '[data-cy-files-list-row-action="delete"]',
                '[data-action="delete"]',
                'button[aria-label*="Delete"], button[aria-label*="Apagar"]',
                'li:has(button[aria-label*="Delete"]), li:has(button[aria-label*="Apagar"])',
            ];

            for (const sel of selectors) {
                try {
                    menu.querySelectorAll(sel).forEach(el => {
                        el.closest('li') ? el.closest('li').remove() : el.remove();
                        this.log('[FolderProtection] Removed blocked action from menu:', sel);
                    });
                } catch (_) { /* :has() may not be supported */ }
            }
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FolderProtectionUI.init());
    } else {
        FolderProtectionUI.init();
    }

    window.FolderProtectionUI = FolderProtectionUI;

})();
