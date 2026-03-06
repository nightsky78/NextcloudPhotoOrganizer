<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="people">
		<div class="people__header">
			<h2>People</h2>
			<div class="people__header-actions">
				<div class="people__scope-toggle">
					<button type="button"
						class="people__scope-btn"
						:class="{ 'people__scope-btn--active': localScope === 'all' }"
						@click="setScope('all')">
						Whole drive
					</button>
					<button type="button"
						class="people__scope-btn"
						:class="{ 'people__scope-btn--active': localScope === 'photos' }"
						@click="setScope('photos')">
						Photos folder
					</button>
				</div>
				<div v-if="!loading" class="people__stats">
					<span><strong>{{ totalFaceImages }}</strong> face images</span>
					<span><strong>{{ clusters.length }}</strong> clusters</span>
				</div>
			</div>
		</div>

		<div v-if="loading" class="people__loading">
			<NcLoadingIcon :size="44" />
			<p>Loading face clusters…</p>
		</div>

		<div v-else-if="clusters.length === 0" class="people__empty">
			<div class="people__empty-icon">👤</div>
			<h3>No people clusters yet</h3>
			<p>Only photos with detected faces are grouped in this view.</p>
		</div>

		<div v-else class="people__clusters">
			<div v-for="cluster in clusters" :key="cluster.id" class="people__cluster">
				<div class="people__cluster-head">
					<h3>{{ cluster.name }}</h3>
					<span>{{ cluster.count }} photo(s)</span>
				</div>
				<div class="people__grid">
					<div v-for="file in cluster.files" :key="file.fileId" class="people__item">
						<img :src="previewUrl(file.fileId, 220, 220)"
							:alt="file.filePath"
							loading="lazy">
						<div class="people__meta" :title="file.filePath">{{ file.filePath }}</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import {
	fetchPeopleClusters,
	previewUrl,
} from '../services/api.js'

export default {
	name: 'PeopleView',

	components: {
		NcLoadingIcon,
	},

	props: {
		scope: {
			type: String,
			default: 'all',
		},
	},

	data() {
		return {
			localScope: this.scope,
			loading: true,
			clusters: [],
			totalFaceImages: 0,
		}
	},

	watch: {
		scope(newScope) {
			if (newScope !== this.localScope) {
				this.localScope = newScope
				this.loadClusters()
			}
		},
	},

	async created() {
		await this.loadClusters()
	},

	methods: {
		previewUrl,

		async setScope(scope) {
			if (scope !== 'all' && scope !== 'photos') {
				return
			}
			if (scope === this.localScope) {
				return
			}
			this.localScope = scope
			this.$emit('scope-change', scope)
			await this.loadClusters()
		},

		async loadClusters() {
			this.loading = true
			try {
				const result = await fetchPeopleClusters(this.localScope)
				this.clusters = result.clusters || []
				this.totalFaceImages = result.total_face_images || 0
			} catch (err) {
				console.error('PhotoDedup: failed to load people clusters', err)
				this.clusters = []
				this.totalFaceImages = 0
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.people {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.people__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 20px;
}

.people__header h2 {
	margin: 0;
}

.people__header-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.people__scope-toggle {
	display: inline-flex;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	overflow: hidden;
}

.people__scope-btn {
	border: none;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	padding: 6px 12px;
	font-size: 0.85em;
	cursor: pointer;
}

.people__scope-btn:hover {
	background: var(--color-background-hover);
}

.people__scope-btn--active {
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}

.people__stats {
	display: flex;
	gap: 16px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__stats strong {
	color: var(--color-main-text);
}

.people__loading,
.people__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 60px 0;
	color: var(--color-text-maxcontrast);
}

.people__empty-icon {
	font-size: 2rem;
}

.people__clusters {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.people__cluster {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 14px;
	background: var(--color-main-background);
}

.people__cluster-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.people__cluster-head h3 {
	margin: 0;
	font-size: 1.05em;
}

.people__cluster-head span {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 10px;
}

.people__item {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.people__item img {
	width: 100%;
	aspect-ratio: 1 / 1;
	object-fit: cover;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
}

.people__meta {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
