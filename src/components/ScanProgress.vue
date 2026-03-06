<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Scan progress indicator.
-->
<template>
	<div class="scan-progress">
		<div class="scan-progress__bar-track">
			<div class="scan-progress__bar-fill" :style="{ width: percent + '%' }" />
		</div>
		<p class="scan-progress__text">
			Scanning: {{ processed }} / {{ total }} files ({{ percent }}%)
		</p>
	</div>
</template>

<script>
export default {
	name: 'ScanProgress',

	props: {
		status: {
			type: String,
			default: 'idle',
		},
		total: {
			type: Number,
			default: 0,
		},
		processed: {
			type: Number,
			default: 0,
		},
	},

	computed: {
		percent() {
			if (this.total === 0) return 0
			return Math.min(100, Math.round((this.processed / this.total) * 100))
		},
	},
}
</script>

<style scoped>
.scan-progress {
	margin-bottom: 20px;
}

.scan-progress__bar-track {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.scan-progress__bar-fill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 4px;
	transition: width 0.4s ease;
}

.scan-progress__text {
	margin: 8px 0 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}
</style>
