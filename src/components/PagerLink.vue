<template>
	<a
		v-if="href && !current"
		:class="root_class"
		:href="href"
		:rel="rel"
		:title="label"
		:aria-label="label"
	><span
		v-if="icon"
		class="advancedcategories-pager__glyph"
		aria-hidden="true"
	><CdxIcon :icon="icon" /></span><slot v-else>{{ label }}</slot></a>
	<span
		v-else
		:class="root_class"
		:aria-disabled="current ? undefined : 'true'"
		:aria-current="current ? 'page' : undefined"
		:title="label"
		:aria-label="label"
	><span
		v-if="icon"
		class="advancedcategories-pager__glyph"
		aria-hidden="true"
	><CdxIcon :icon="icon" /></span><slot v-else>{{ label }}</slot></span>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { CdxIcon } from '@wikimedia/codex';
import type { Icon } from '@wikimedia/codex-icons';

const props = defineProps<{
	href: string | null;
	label: string;
	icon?: Icon;
	current?: boolean;
	rel?: string;
}>();

const root_class = computed( () => [
	'advancedcategories-pager__button',
	props.icon ? 'advancedcategories-pager__icon' : 'advancedcategories-pager__page',
	props.icon && !props.href ? 'advancedcategories-pager__icon--disabled' : null,
	!props.icon && props.current ? 'advancedcategories-pager__page--current' : null,
	!props.icon && !props.current && !props.href ? 'advancedcategories-pager__page--disabled' : null
] );
</script>
