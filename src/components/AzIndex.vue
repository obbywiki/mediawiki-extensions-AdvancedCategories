<template>
	<nav
		class="advancedcategories-az-index"
		:aria-label="aria_label"
	>
		<div class="advancedcategories-az-index__letters">
			<template
				v-for="item in items"
				:key="item.id"
			>
				<a
					v-if="item.href"
					:class="letter_class( item )"
					:href="item.href"
					:title="letter_title( item )"
					:aria-current="item.current ? 'true' : undefined"
					@click="on_letter_click( $event, item )"
				>
					<span class="advancedcategories-az-index__glyph">{{ item_label( item ) }}</span>
					<span
						v-if="item.page !== null"
						class="advancedcategories-az-index__page"
					>{{ format_number( item.page ) }}</span>
				</a>
				<span
					v-else
					:class="letter_class( item )"
					:title="letter_title( item )"
				>
					<span class="advancedcategories-az-index__glyph">{{ item_label( item ) }}</span>
				</span>
			</template>
		</div>
	</nav>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted } from 'vue';
import type { CategoryLetter } from '../types/mw';
import { index_letters, msg, OTHER_BUCKET, type IndexLetter } from '../utils/letters';

const props = defineProps<{ letters: CategoryLetter[]; }>();

const items = computed( () => index_letters( props.letters ) );
const aria_label = computed( () => msg( 'advancedcategories-az-index-aria' ) );
const other_label = computed( () => msg( 'advancedcategories-az-index-other' ) );

onMounted( () => {
	nextTick( () => {
		const current = document.querySelector( '#advancedcategories-pages-app .advancedcategories-az-index__letter--current' );
		if ( current ) {
			current.scrollIntoView( {
				inline: 'center',
				block: 'nearest'
			} );
		}
	} );
} );

function item_label( item: IndexLetter ): string {
	return item.letter === OTHER_BUCKET ? other_label.value : item.label;
}

function letter_class( item: IndexLetter ): ( string | boolean )[] {
	return [
		'advancedcategories-az-index__letter',
		item.letter === OTHER_BUCKET ? 'advancedcategories-az-index__letter--other' : false,
		item.href ? false : 'advancedcategories-az-index__letter--empty',
		item.current ? 'advancedcategories-az-index__letter--current' : false
	];
}

function letter_title( item: IndexLetter ): string {
	const label = item_label( item );
	if ( item.page === null ) {
		return msg( 'advancedcategories-az-index-letter', label );
	}

	return msg(
		'advancedcategories-az-index-letter-page',
		label,
		format_number( item.page )
	);
}

function on_letter_click( event: MouseEvent, item: IndexLetter ): void {
	if ( !item.href ) {
		event.preventDefault();
		return;
	}

	let next: URL;
	try {
		next = new URL( item.href, window.location.href );
	} catch {
		return;
	}

	const here = new URL( window.location.href );
	next.hash = '';
	here.hash = '';
	if ( next.pathname !== here.pathname || next.search !== here.search ) { return; }

	event.preventDefault();
	const group = document.getElementById( item.id );
	if ( group ) {
		group.scrollIntoView( { block: 'start' } );
	}
}

function format_number( value: number ): string {
	return mw.language.convertNumber( value );
}
</script>
