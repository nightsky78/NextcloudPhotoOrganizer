/**
 * SPDX-FileCopyrightText: 2026 Johannes
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vue app entry point for PhotoDedup.
 */

import Vue from 'vue'
import App from './App.vue'

// Silence Vue production/dev tip
Vue.config.productionTip = false

// Mount the app into the Nextcloud content area
const appElement = document.getElementById('photodedup-content')
if (appElement) {
	new Vue({
		render: h => h(App),
	}).$mount(appElement)
}
