<template>
	<div class="advancedcategories-pages">
		<AzIndex :letters="config.letters" />
		<PagesPager :pagination="config.pagination" />
		<PagesGrid
			:pages="config.pages"
			:columns="config.columns"
		/>
		<PagesPager :pagination="config.pagination" />
	</div>
</template>

<script setup lang="ts">
import type { AdvancedCategoriesConfig } from '../types/mw';
import AzIndex from './AzIndex.vue';
import PagesGrid from './PagesGrid.vue';
import PagesPager from './PagesPager.vue';

defineProps<{ config: AdvancedCategoriesConfig; }>();
</script>
