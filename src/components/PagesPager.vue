<template>
	<nav
		v-if="visible"
		class="advancedcategories-pager"
		:aria-label="aria_label"
	>
		<div class="advancedcategories-pager__controls">
			<div class="advancedcategories-pager__status">
				{{ status }}
			</div>
			<PagerLink
				:href="pagination.prev_url"
				:label="prev_label"
				:icon="icon_prev"
				rel="prev"
			/>
			<template
				v-for="slot in slots"
				:key="slot.kind === 'ellipsis' ? slot.key : 'page-' + slot.page"
			>
				<PagerJump
					v-if="slot.kind === 'ellipsis'"
					:current="pagination.page"
					:page_count="pagination.page_count"
					:href_for="page_href"
				/>
				<PagerLink
					v-else
					:href="page_href( slot.page )"
					:label="page_label( slot.page )"
					:current="slot.current"
				>
					{{ format_number( slot.page ) }}
				</PagerLink>
			</template>
			<PagerLink
				:href="pagination.next_url"
				:label="next_label"
				:icon="icon_next"
				rel="next"
			/>
		</div>
	</nav>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import {
	cdxIconNext,
	cdxIconPrevious
} from '@wikimedia/codex-icons';
import type { CategoryPagination } from '../types/mw';
import { msg } from '../utils/letters';
import { visible_pager_slots } from '../utils/pager';
import PagerJump from './PagerJump.vue';
import PagerLink from './PagerLink.vue';

const props = defineProps<{
	pagination: CategoryPagination;
}>();

const icon_prev = cdxIconPrevious;
const icon_next = cdxIconNext;

const visible = computed( () => {
	const paging = props.pagination;
	return paging.page_count > 1 || !!paging.prev_url || !!paging.next_url;
} );

const aria_label = computed( () => msg( 'advancedcategories-pager-aria' ) );
const prev_label = computed( () => msg( 'table_pager_prev' ) );
const next_label = computed( () => msg( 'table_pager_next' ) );

const href_by_page = computed( () => {
	const hrefs = new Map<number, string | null>();
	for ( const link of props.pagination.page_links ) {
		hrefs.set( link.page, link.url );
	}

	return hrefs;
} );

const slots = computed( () => visible_pager_slots(
	props.pagination.page,
	props.pagination.page_count,
	new Set( href_by_page.value.keys() )
) );

function page_href( page: number ): string | null {
	if ( page === props.pagination.page ) { return null; }

	const href = href_by_page.value.get( page );
	if ( href ) { return href; }
	if ( href === null ) { return null; }
	if ( page === 1 ) { return props.pagination.first_url; }
	if ( page === props.pagination.page_count ) { return props.pagination.last_url; }

	return null;
}

function page_label( page: number ): string {
	return msg( 'advancedcategories-pager-page', format_number( page ) );
}

function format_number( value: number ): string {
	return mw.language.convertNumber( value );
}

const status = computed( () => {
	const paging = props.pagination;
	if ( paging.precise && paging.start > 0 ) {
		return msg(
			'advancedcategories-pager-status',
			format_number( paging.start ),
			format_number( paging.end ),
			format_number( paging.total )
		);
	}

	return msg( 'advancedcategories-pager-status-total', format_number( paging.total ) );
} );
</script>
