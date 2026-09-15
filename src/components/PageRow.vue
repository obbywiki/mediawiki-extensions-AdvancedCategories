<template>
	<tr
		class="advancedcategories-row"
		:class="{ 'advancedcategories-row--redirect': page.is_redirect }"
	>
		<td class="advancedcategories-row__page">
			<div class="advancedcategories-row__page-inner">
				<a
					class="advancedcategories-row__thumb-link"
					:href="page.url"
					tabindex="-1"
					aria-hidden="true"
				>
					<span
						class="advancedcategories-row__thumb"
						:style="thumb_style"
					>
						<img
							v-if="page.thumbnail"
							class="advancedcategories-row__image"
							:src="page.thumbnail.url"
							alt=""
							:width="page.thumbnail.width"
							:height="page.thumbnail.height"
						>
					</span>
				</a>
				<div class="advancedcategories-row__text">
					<a
						class="advancedcategories-row__name"
						:href="page.url"
					>
						{{ page.display_title }}
					</a>
					<div
						v-if="page.description"
						class="advancedcategories-row__description"
					>
						{{ page.description }}
					</div>
				</div>
			</div>
		</td>
		<td
			v-for="column in columns"
			:key="column.key"
			class="advancedcategories-row__cargo"
		>
			{{ cargo_text( page.cargo[ column.key ] ) }}
		</td>
	</tr>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { CargoValue, CategoryColumn, CategoryPage } from '../types/mw';

const props = withDefaults( defineProps<{
	page: CategoryPage;
	columns?: CategoryColumn[];
}>(), {
	columns: () => []
} );

const min_ratio = 1 / 2;
const max_ratio = 21 / 9;

const thumb_style = computed( () => {
	const thumb = props.page.thumbnail;
	if ( !thumb || thumb.width < 1 || thumb.height < 1 ) {
		return { aspectRatio: '16 / 9' };
	}

	const ratio = Math.min( max_ratio, Math.max( min_ratio, thumb.width / thumb.height ) );

	return { aspectRatio: String( ratio ) };
} );

function cargo_text( value: CargoValue | undefined ): string {
	if ( value === undefined || value === null ) { return ''; }
	if ( Array.isArray( value ) ) { return value.filter( ( part ) => part !== '' ).join( ', ' ); }

	return value;
}
</script>
