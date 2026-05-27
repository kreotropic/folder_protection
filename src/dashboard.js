import Vue from 'vue'
import ProtectedFoldersWidget from './components/ProtectedFoldersWidget.vue'

/**
 * OCA.Dashboard.register é inicializado pelo dashboard-main.js do Nextcloud
 * dentro do seu próprio DOMContentLoaded — que corre DEPOIS do nosso porque foi
 * registado depois. Por isso fazemos polling a cada 50 ms até estar disponível.
 */
function tryRegister() {
	if (typeof OCA !== 'undefined' && OCA.Dashboard && typeof OCA.Dashboard.register === 'function') {
		OCA.Dashboard.register('folder_protection', (el) => {
			const View = Vue.extend(ProtectedFoldersWidget)
			new View().$mount(el)
		})
		console.log('[FolderProtection] widget registered successfully')
	} else {
		window.setTimeout(tryRegister, 50)
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', tryRegister)
} else {
	tryRegister()
}
