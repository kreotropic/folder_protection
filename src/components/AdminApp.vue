<template>
    <div class="folder-protection-admin">
        <!-- Botão Adicionar -->
        <div class="add-protection-section">
            <button @click="showAddModal = true" class="button primary">
                <span class="icon icon-add"></span>
                {{ t('folder_protection', 'Add Protection') }}
            </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="loading-container">
            <span class="icon-loading"></span>
            {{ t('folder_protection', 'Loading protected folders...') }}
        </div>

        <!-- Lista de Pastas Protegidas -->
        <div v-else-if="folders.length > 0" class="protected-folders-list">
            <h3>{{ t('folder_protection', 'Protected Folders') }}</h3>
            
            <div v-for="folder in folders" :key="folder.id" class="folder-item">
                <div class="folder-icon">📁</div>
                <div class="folder-details">
                    <div class="folder-path">{{ folder.path }}</div>
                    <div class="folder-meta">
                        <span v-if="folder.created_by">
                            {{ t('folder_protection', 'Created by') }}: {{ folder.created_by }}
                        </span>
                        <span v-if="folder.created_at">
                            | {{ formatDate(folder.created_at) }}
                        </span>
                    </div>
                    <div v-if="folder.reason" class="folder-reason">
                        {{ folder.reason }}
                    </div>
                </div>
                <div class="folder-actions">
                    <button @click="removeProtection(folder)" 
                            class="button icon-delete" 
                            :title="t('folder_protection', 'Remove protection')">
                    </button>
                </div>
            </div>
        </div>

        <!-- Mensagem se vazio -->
        <div v-else class="empty-content">
            <div class="icon-folder"></div>
            <h3>{{ t('folder_protection', 'No protected folders') }}</h3>
            <p>{{ t('folder_protection', 'Add folders to protect them from being moved, copied, or deleted.') }}</p>
        </div>

        <!-- Modal Adicionar -->
        <div v-if="showAddModal" class="modal-overlay" @click="showAddModal = false">
            <div class="modal-content" @click.stop>
                <h3>{{ t('folder_protection', 'Add Folder Protection') }}</h3>
                
                <form @submit.prevent="addProtection">
                    <div class="form-group">
                        <label for="folder-path">
                            {{ t('folder_protection', 'Folder Path') }}
                        </label>
                        <input 
                            id="folder-path"
                            v-model="newFolder.path" 
                            type="text" 
                            :placeholder="t('folder_protection', '/files/folder_name')"
                            required
                        />
                    </div>

                    <div class="form-group">
                        <label for="folder-reason">
                            {{ t('folder_protection', 'Reason (optional)') }}
                        </label>
                        <textarea 
                            id="folder-reason"
                            v-model="newFolder.reason" 
                            :placeholder="t('folder_protection', 'Why is this folder protected?')"
                            rows="3"
                        ></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" @click="showAddModal = false" class="button">
                            {{ t('folder_protection', 'Cancel') }}
                        </button>
                        <button type="submit" class="button primary" :disabled="submitting">
                            {{ submitting ? t('folder_protection', 'Adding...') : t('folder_protection', 'Add Protection') }}
                        </button>
                    </div>
                </form>

                <div v-if="error" class="error-message">
                    {{ error }}
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
    name: 'AdminApp',
    
    data() {
        return {
            folders: [],
            loading: true,
            showAddModal: false,
            submitting: false,
            error: null,
            newFolder: {
                path: '',
                reason: ''
            }
        }
    },

    mounted() {
        this.loadFolders()
    },

    methods: {
        async loadFolders() {
            this.loading = true
            try {
                const response = await axios.get(generateUrl('/apps/folder_protection/api/list'))
                if (response.data.success) {
                    this.folders = response.data.folders
                }
            } catch (error) {
                console.error('Error loading folders:', error)
                showError(this.t('folder_protection', 'Failed to load protected folders'))
            } finally {
                this.loading = false
            }
        },

        async addProtection() {
            this.submitting = true
            this.error = null

            try {
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/protect'),
                    {
                        path: this.newFolder.path,
                        reason: this.newFolder.reason,
                        userId: OC.getCurrentUser().uid
                    }
                )

                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Folder protected successfully'))
                    this.showAddModal = false
                    this.newFolder = { path: '', reason: '' }
                    await this.loadFolders()
                } else {
                    this.error = response.data.message
                }
            } catch (error) {
                console.error('Error adding protection:', error)
                this.error = error.response?.data?.message || this.t('folder_protection', 'Failed to protect folder')
            } finally {
                this.submitting = false
            }
        },

        async removeProtection(folder) {
            if (!confirm(this.t('folder_protection', 'Remove protection from "{path}"?', { path: folder.path }))) {
                return
            }

            try {
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/unprotect'),
                    { id: folder.id }
                )

                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Protection removed successfully'))
                    await this.loadFolders()
                } else {
                    showError(response.data.message)
                }
            } catch (error) {
                console.error('Error removing protection:', error)
                showError(this.t('folder_protection', 'Failed to remove protection'))
            }
        },

        formatDate(timestamp) {
            return new Date(timestamp * 1000).toLocaleString()
        },

        t(app, text, vars = {}) {
            return OC.L10N.translate(app, text, vars)
        }
    }
}
</script>

<style scoped>
.folder-protection-admin {
    max-width: 900px;
}

.add-protection-section {
    margin-bottom: 20px;
}

.loading-container {
    text-align: center;
    padding: 40px;
    color: var(--color-text-maxcontrast);
}

.protected-folders-list h3 {
    margin-bottom: 15px;
}

.folder-item {
    display: flex;
    align-items: center;
    padding: 15px;
    margin-bottom: 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.folder-icon {
    font-size: 24px;
    margin-right: 15px;
}

.folder-details {
    flex: 1;
}

.folder-path {
    font-weight: bold;
    margin-bottom: 5px;
}

.folder-meta {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.folder-reason {
    margin-top: 5px;
    font-style: italic;
    color: var(--color-text-lighter);
}

.folder-actions button {
    opacity: 0.7;
}

.folder-actions button:hover {
    opacity: 1;
}

.empty-content {
    text-align: center;
    padding: 60px 20px;
    color: var(--color-text-maxcontrast);
}

.empty-content .icon-folder {
    font-size: 64px;
    opacity: 0.3;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.modal-content {
    background: var(--color-main-background);
    padding: 30px;
    border-radius: var(--border-radius-large);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.error-message {
    margin-top: 15px;
    padding: 10px;
    background: var(--color-error);
    color: white;
    border-radius: var(--border-radius);
}
</style>
