<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Root component for the Photo Deduplicator app.
-->
<template>
	<NcContent app-name="photodedup">
		<NcAppContent>
			<!-- Tab navigation -->
			<div class="photodedup-tabs">
				<button class="photodedup-tabs__tab"
					:class="{ 'photodedup-tabs__tab--active': activeTab === 'duplicates' }"
					@click="activeTab = 'duplicates'">
					<MagnifyIcon :size="18" />
					Duplicates
				</button>
				<button class="photodedup-tabs__tab"
					:class="{ 'photodedup-tabs__tab--active': activeTab === 'classifier' }"
					@click="activeTab = 'classifier'">
					<TagMultipleIcon :size="18" />
					Classifier
				</button>
				<button class="photodedup-tabs__tab"
					:class="{ 'photodedup-tabs__tab--active': activeTab === 'people' }"
					@click="activeTab = 'people'">
					<AccountGroupIcon :size="18" />
					People
				</button>
				<button class="photodedup-tabs__tab"
					:class="{ 'photodedup-tabs__tab--active': activeTab === 'locations' }"
					@click="activeTab = 'locations'">
					<MapMarkerIcon :size="18" />
					Locations
				</button>
			</div>

			<!-- Duplicates tab -->
			<div v-show="activeTab === 'duplicates'" class="photodedup">
				<!-- Header bar with stats and actions -->
				<div class="photodedup__header">
					<h2>Photo Deduplicator</h2>
					<div class="photodedup__header-actions">
						<div class="photodedup__scope-toggle">
							<button type="button"
								class="photodedup__scope-btn"
								:class="{ 'photodedup__scope-btn--active': duplicatesScope === 'all' }"
								@click="setDuplicatesScope('all')">
								Whole drive
							</button>
							<button type="button"
								class="photodedup__scope-btn"
								:class="{ 'photodedup__scope-btn--active': duplicatesScope === 'photos' }"
								@click="setDuplicatesScope('photos')">
								Photos folder
							</button>
						</div>
						<div v-if="stats" class="photodedup__stats">
							<span class="photodedup__stat">
								<strong>{{ stats.duplicate_groups }}</strong> duplicate groups
							</span>
							<span class="photodedup__stat">
								<strong>{{ stats.duplicate_files }}</strong> files involved
							</span>
							<span class="photodedup__stat">
								<strong>{{ formatBytes(stats.wasted_bytes) }}</strong> recoverable
							</span>
						</div>
					</div>
				</div>

				<!-- Scan progress -->
				<ScanProgress v-if="scanning"
					:status="scanProgress.status"
					:total="scanProgress.total"
					:processed="scanProgress.processed" />

				<!-- Bulk action bar -->
				<div v-if="selectedFiles.length > 0" class="photodedup__bulk-bar">
					<span>{{ selectedFiles.length }} file(s) selected</span>
					<NcButton type="error" @click="confirmBulkDelete">
						Delete selected
					</NcButton>
					<NcButton @click="clearSelection">
						Clear selection
					</NcButton>
				</div>

				<!-- Duplicate groups list -->
				<div v-if="!loading && groups.length > 0" class="photodedup__groups">
					<DuplicateGroup v-for="group in groups"
						:key="group.contentHash"
						:group="group"
						:selected-files="selectedFiles"
						:retained-file-ids="retainedFileIds"
						@delete-file="onDeleteFile"
						@keep-file="onKeepFile"
						@toggle-select="onToggleSelect" />
				</div>

				<!-- Pagination -->
				<div v-if="totalGroups > groups.length" class="photodedup__load-more">
					<NcButton @click="loadMore">
						Load more ({{ groups.length }} of {{ totalGroups }})
					</NcButton>
				</div>

				<!-- Empty state -->
				<EmptyState v-if="!loading && !scanning && groups.length === 0"
					:has-scanned="hasScanned" />

				<!-- Loading spinner for initial load -->
				<div v-if="loading" class="photodedup__loading">
					<NcLoadingIcon :size="44" />
					<p>Loading duplicates…</p>
				</div>
			</div>

			<!-- Classifier tab -->
			<ClassifierView v-if="activeTab === 'classifier'"
				:scope="classifierScope"
				@scope-change="classifierScope = $event" />

			<PeopleView v-if="activeTab === 'people'"
				:scope="insightsScope"
				@scope-change="insightsScope = $event" />

			<LocationsView v-if="activeTab === 'locations'"
				:scope="insightsScope"
				@scope-change="insightsScope = $event" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import TagMultipleIcon from 'vue-material-design-icons/TagMultiple.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'

import DuplicateGroup from './components/DuplicateGroup.vue'
import EmptyState from './components/EmptyState.vue'
import ScanProgress from './components/ScanProgress.vue'
import ClassifierView from './components/ClassifierView.vue'
import PeopleView from './components/PeopleView.vue'
import LocationsView from './components/LocationsView.vue'

import {
	fetchDuplicates,
	fetchStats,
	fetchScanStatus,
	deleteFile,
	bulkDeleteFiles,
} from './services/api.js'

const PAGE_SIZE = 50

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		MagnifyIcon,
		TagMultipleIcon,
		AccountGroupIcon,
		MapMarkerIcon,
		DuplicateGroup,
		EmptyState,
		ScanProgress,
		ClassifierView,
		PeopleView,
		LocationsView,
	},

	data() {
		return {
			activeTab: 'duplicates',
			duplicatesScope: 'all',
			classifierScope: 'all',
			insightsScope: 'all',
			groups: [],
			totalGroups: 0,
			stats: null,
			loading: true,
			scanning: false,
			hasScanned: false,
			scanProgress: { status: 'idle', total: 0, processed: 0 },
			selectedFiles: [],
			retainedFileIds: [],
			pollTimer: null,
		}
	},

	async created() {
		await this.loadInitialData()
	},

	beforeDestroy() {
		this.stopPolling()
	},

	methods: {
		async loadInitialData() {
			this.loading = true
			try {
				const [dupResult, statsResult, statusResult] = await Promise.allSettled([
					fetchDuplicates(PAGE_SIZE, 0, this.duplicatesScope),
					fetchStats(this.duplicatesScope),
					fetchScanStatus(),
				])

				if (dupResult.status === 'fulfilled') {
					this.groups = dupResult.value.groups
					this.totalGroups = dupResult.value.total
				} else {
					console.error('PhotoDedup: failed to load duplicates', dupResult.reason)
				}

				if (statsResult.status === 'fulfilled') {
					this.stats = statsResult.value
				} else {
					console.error('PhotoDedup: failed to load stats', statsResult.reason)
				}

				const statusData = statusResult.status === 'fulfilled' ? statusResult.value : { status: 'unknown' }
				this.scanProgress = statusData
				this.scanning = statusData.status === 'scanning'
				this.hasScanned = statusData.status === 'completed' || this.totalGroups > 0

				if (this.scanning) {
					this.startPolling()
				}
			} catch (err) {
				console.error('PhotoDedup: failed to load data', err)
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			try {
				const data = await fetchDuplicates(PAGE_SIZE, this.groups.length, this.duplicatesScope)
				this.groups.push(...data.groups)
			} catch (err) {
				console.error('PhotoDedup: failed to load more groups', err)
			}
		},

		startPolling() {
			this.stopPolling()
			this.pollTimer = setInterval(async () => {
				try {
					this.scanProgress = await fetchScanStatus()
					if (this.scanProgress.status !== 'scanning') {
						this.stopPolling()
					}
				} catch (err) {
					// Ignore polling errors
				}
			}, 2000)
		},

		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},

		async setDuplicatesScope(scope) {
			if (scope !== 'all' && scope !== 'photos') {
				return
			}
			if (this.duplicatesScope === scope) {
				return
			}

			this.duplicatesScope = scope
			this.groups = []
			this.totalGroups = 0
			this.selectedFiles = []
			this.retainedFileIds = []
			await this.loadInitialData()
		},

		async onDeleteFile(fileId) {
			if (!confirm('Delete this file? It will be moved to the trash bin.')) {
				return
			}
			try {
				let result
				try {
					result = await deleteFile(fileId)
				} catch (err) {
					result = err?.response?.data
					if (!result || typeof result !== 'object') {
						throw err
					}
				}

				if (result.success) {
					this.retainedFileIds = this.retainedFileIds.filter(id => id !== fileId)
					this.removeFileFromGroups(fileId)
					this.stats = await fetchStats(this.duplicatesScope)
				} else {
					alert(result.message || 'Deletion failed.')
				}
			} catch (err) {
				console.error('PhotoDedup: delete failed', err)
				const msg = err?.response?.data?.message || err?.response?.data?.error || 'Failed to delete file.'
				alert(msg)
			}
		},

		async onKeepFile(keepFileId, groupFileIds, contentHash) {
			const filesToDelete = groupFileIds.filter(fileId => fileId !== keepFileId)
			if (filesToDelete.length === 0) {
				return
			}

			if (!confirm(`Keep this file and delete ${filesToDelete.length} other duplicate(s)? They will be moved to the trash bin.`)) {
				return
			}

			try {
				const result = await bulkDeleteFiles(filesToDelete)
				for (const r of result.results) {
					if (r.success) {
						this.removeFileFromGroups(r.fileId, contentHash)
						this.selectedFiles = this.selectedFiles.filter(selectedId => selectedId !== r.fileId)
						this.retainedFileIds = this.retainedFileIds.filter(id => id !== r.fileId)
					}
				}

				if (!this.retainedFileIds.includes(keepFileId)) {
					this.retainedFileIds.push(keepFileId)
				}

				this.stats = await fetchStats(this.duplicatesScope)

				if (result.failed > 0) {
					alert(`${result.deleted} deleted, ${result.failed} failed.`)
				}
			} catch (err) {
				console.error('PhotoDedup: keep-one delete failed', err)
				alert('Failed to delete duplicate copies.')
			}
		},

		onToggleSelect(fileId) {
			const idx = this.selectedFiles.indexOf(fileId)
			if (idx === -1) {
				this.selectedFiles.push(fileId)
			} else {
				this.selectedFiles.splice(idx, 1)
			}
		},

		clearSelection() {
			this.selectedFiles = []
		},

		async confirmBulkDelete() {
			const count = this.selectedFiles.length
			if (!confirm(`Delete ${count} selected file(s)? They will be moved to the trash bin.`)) {
				return
			}
			try {
				let result
				try {
					result = await bulkDeleteFiles([...this.selectedFiles])
				} catch (err) {
					result = err?.response?.data
					if (!result || typeof result !== 'object') {
						throw err
					}
				}

				for (const r of result.results) {
					if (r.success) {
						this.retainedFileIds = this.retainedFileIds.filter(id => id !== r.fileId)
						this.removeFileFromGroups(r.fileId)
					}
				}
				this.selectedFiles = []
				this.stats = await fetchStats(this.duplicatesScope)

				if (result.failed > 0) {
					alert(`${result.deleted} deleted, ${result.failed} failed (last copies are protected).`)
				}
			} catch (err) {
				console.error('PhotoDedup: bulk delete failed', err)
				const msg = err?.response?.data?.message || err?.response?.data?.error || 'Bulk delete failed.'
				alert(msg)
			}
		},

		removeFileFromGroups(fileId, preserveSingleGroupHash = null) {
			for (let i = this.groups.length - 1; i >= 0; i--) {
				const group = this.groups[i]
				const idx = group.files.findIndex(f => f.fileId === fileId)
				if (idx !== -1) {
					group.files.splice(idx, 1)
					group.count = group.files.length
					group.totalSize = group.files.reduce((sum, file) => sum + file.fileSize, 0)
					// Remove group entirely if fewer than 2 files remain
					if (group.files.length < 2 && group.contentHash !== preserveSingleGroupHash) {
						this.groups.splice(i, 1)
						this.totalGroups--
					}
					break
				}
			}
		},

		formatBytes(bytes) {
			if (bytes === 0) return '0 B'
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			const i = Math.floor(Math.log(bytes) / Math.log(1024))
			const val = (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)
			return `${val} ${units[i]}`
		},
	},
}
</script>

<style scoped>
.photodedup-tabs {
	display: flex;
	gap: 0;
	border-bottom: 2px solid var(--color-border);
	margin: 0 20px;
	padding-top: 8px;
}

.photodedup-tabs__tab {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 10px 20px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 1em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s;
}

.photodedup-tabs__tab:hover {
	color: var(--color-main-text);
}

.photodedup-tabs__tab--active {
	color: var(--color-primary-element);
	border-bottom-color: var(--color-primary-element);
}

.photodedup {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.photodedup__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 20px;
}

.photodedup__header h2 {
	margin: 0;
	font-size: 1.5em;
}

.photodedup__header-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.photodedup__scope-toggle {
	display: inline-flex;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	overflow: hidden;
}

.photodedup__scope-btn {
	border: none;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	padding: 6px 12px;
	font-size: 0.85em;
	cursor: pointer;
}

.photodedup__scope-btn:hover {
	background: var(--color-background-hover);
}

.photodedup__scope-btn--active {
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}

.photodedup__stats {
	display: flex;
	gap: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.photodedup__stat strong {
	color: var(--color-main-text);
}

.photodedup__bulk-bar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	margin-bottom: 16px;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	position: sticky;
	top: 8px;
	z-index: 10;
}

.photodedup__groups {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.photodedup__load-more {
	display: flex;
	justify-content: center;
	margin-top: 20px;
}

.photodedup__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 60px 0;
	color: var(--color-text-maxcontrast);
}
</style>
