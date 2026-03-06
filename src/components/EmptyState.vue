<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Empty state shown when no duplicates are found.
-->
<template>
	<NcEmptyContent :name="title" :description="description">
		<template #icon>
			<CheckCircleIcon v-if="hasScanned" :size="64" />
			<MagnifyIcon v-else :size="64" />
		</template>
	</NcEmptyContent>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'EmptyState',

	components: {
		NcEmptyContent,
		CheckCircleIcon,
		MagnifyIcon,
	},

	props: {
		hasScanned: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		title() {
			return this.hasScanned
				? 'No duplicates found'
				: 'Get started'
		},

		description() {
			return this.hasScanned
				? 'Your photo library has no exact duplicate files. Great!'
				: 'Click "Scan for duplicates" to analyze your photo library.'
		},
	},
}
</script>
