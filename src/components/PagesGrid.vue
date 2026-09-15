<template>
	<table
		class="advancedcategories-agrid"
		:aria-label="caption"
	>
		<thead>
			<tr>
				<th scope="col">
					{{ page_heading }}
				</th>
			</tr>
		</thead>
		<tbody
			v-for="group in groups"
			:id="group.id"
			:key="group.key"
		>
			<tr class="advancedcategories-group-heading">
				<th scope="colgroup">
					{{ group.label }}
				</th>
			</tr>
			<PageRow
				v-for="page in group.pages"
				:key="page.page_id"
				:page="page"
			/>
		</tbody>
	</table>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { CategoryPage } from '../types/mw';
import { bucket_letter, letter_id, msg } from '../utils/letters';
import PageRow from './PageRow.vue';

const props = defineProps<{
	pages: CategoryPage[];
}>();

const page_heading = computed( () => msg( 'advancedcategories-column-page' ) );
const caption = computed( () => msg( 'advancedcategories-agrid-caption' ) );

const groups = computed( () => {
	const grouped = new Map<string, CategoryPage[]>();
	for ( const page of props.pages ) {
		const letter = page.letter;
		const existing = grouped.get( letter );
		if ( existing ) {
			existing.push( page );
		} else {
			grouped.set( letter, [ page ] );
		}
	}

	const seen = new Set<string>();
	return [ ...grouped.entries() ].map( ( [ letter, group_pages ] ) => {
		const bucket = bucket_letter( letter );
		const id = seen.has( bucket ) ? undefined : letter_id( bucket );
		if ( id ) {
			seen.add( bucket );
		}

		return {
			key: letter_id( letter ),
			id,
			label: letter === ' ' ? '\u00A0' : letter,
			pages: group_pages
		};
	} );
} );
</script>
