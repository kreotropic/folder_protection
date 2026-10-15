import Vue from 'vue'
import ProtectedFoldersWidget from './components/ProtectedFoldersWidget.vue'

let registered = false

function register(dashboard) {
	if (registered) {
		return
	}
	registered = true
	dashboard.register('folder_protection', (el) => {
		const View = Vue.extend(ProtectedFoldersWidget)
		new View().$mount(el)
	})
	console.log('[FolderProtection] widget registered successfully')
}

function isDashboardApi(value) {
	return value && typeof value.register === 'function'
}

// The dashboard mounts the registered widgets in a single pass, right after its
// first widgets API call returns, and (NC 35) never again for a widget that
// registers later: register() adds a key to a reactive object that only a
// non-deep watcher observes. OCA.Dashboard itself only appears once the
// dashboard app has mounted, a few milliseconds before that pass on a fast
// server. Polling for it lost that race almost every time, leaving the widget
// on its loading spinner, so register from a setter the moment it is assigned.
function waitForDashboard() {
	if (isDashboardApi(window.OCA?.Dashboard)) {
		register(window.OCA.Dashboard)
		return
	}

	window.OCA = window.OCA || {}
	try {
		let current = window.OCA.Dashboard
		Object.defineProperty(window.OCA, 'Dashboard', {
			configurable: true,
			enumerable: true,
			get: () => current,
			set: (value) => {
				current = value
				if (isDashboardApi(value)) {
					register(value)
				}
			},
		})
	} catch (e) {
		// OCA.Dashboard not redefinable; the poll below still covers it.
	}

	// Fallback for the setter being bypassed (e.g. window.OCA replaced wholesale).
	const poll = () => {
		if (registered) {
			return
		}
		if (isDashboardApi(window.OCA?.Dashboard)) {
			register(window.OCA.Dashboard)
		} else {
			window.setTimeout(poll, 50)
		}
	}
	poll()
}

waitForDashboard()
