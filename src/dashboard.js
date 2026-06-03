import Vue from 'vue'
import ProtectedFoldersWidget from './components/ProtectedFoldersWidget.vue'

function tryRegister() {
	if (typeof OCA !== 'undefined' && OCA.Dashboard && typeof OCA.Dashboard.register === 'function') {
		OCA.Dashboard.register('folder_protection', (el) => {
			const View = Vue.extend(ProtectedFoldersWidget)
			new View().$mount(el)
		})
		console.log('[FolderProtection] widget registered successfully')
	} else {
		// Dashboard API is initialised in a separate DOMContentLoaded handler that runs
		// after ours. Retry at 300 ms — aggressive enough to be invisible to the user,
		// conservative enough not to hammer the event loop on every animation frame.
		window.setTimeout(tryRegister, 300)
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', tryRegister)
} else {
	tryRegister()
}
