<template>
	<form
		v-if="is_open"
		class="advancedcategories-pager__jump"
		@submit.prevent="go"
	>
		<input
			ref="input_el"
			v-model="draft"
			class="advancedcategories-pager__jump-input"
			type="text"
			inputmode="numeric"
			autocomplete="off"
			spellcheck="false"
			enterkeyhint="go"
			:size="input_size"
			:maxlength="input_size"
			:aria-label="label"
			:style="{ width: input_width }"
			@keydown.enter.prevent="go"
			@keydown.esc.prevent="close( true )"
			@blur="close( false )"
		>
	</form>
	<button
		v-else
		ref="button_el"
		class="advancedcategories-pager__button advancedcategories-pager__ellipsis"
		type="button"
		:title="label"
		:aria-label="label"
		@click="open_jump"
	>
		…
	</button>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { msg } from '../utils/letters';

const props = defineProps<{
	current: number;
	page_count: number;
	href_for: ( page: number ) => string | null;
}>();

const is_open = ref( false );
const draft = ref( '' );
const input_el = ref<HTMLInputElement | null>( null );
const button_el = ref<HTMLButtonElement | null>( null );

const label = computed( () => msg( 'advancedcategories-pager-jump', mw.language.convertNumber( props.page_count ) ) );
const input_size = computed( () => Math.max( 2, String( props.page_count ).length ) );
const input_width = computed( () => `calc( ${ input_size.value }ch + var(--space-xs) * 2 )` );

async function open_jump(): Promise<void> {
	draft.value = '';
	is_open.value = true;
	await nextTick();
	input_el.value?.focus();
}

function close( restore_focus: boolean ): void {
	is_open.value = false;
	if ( restore_focus ) {
		nextTick( () => {
			button_el.value?.focus();
		} );
	}
}

function parse_page( value: string ): number | null {
	const trimmed = value.trim();
	if ( !/^[0-9]+$/.test( trimmed ) ) {
		return null;
	}

	const page = Number.parseInt( trimmed, 10 );
	if ( !Number.isFinite( page ) ) {
		return null;
	}

	return page;
}

function go(): void {
	const page = parse_page( draft.value );
	if ( page === null ) {
		return;
	}

	const next = Math.min( props.page_count, Math.max( 1, page ) );
	if ( next === props.current ) {
		close( true );
		return;
	}

	const href = props.href_for( next );
	if ( href ) {
		window.location.assign( href );
	}
}
</script>
