export type PagerSlot =
	| { kind: 'page'; page: number; current: boolean }
	| { kind: 'ellipsis'; key: string };

const window_size = 6;

export function pager_slots( current: number, page_count: number ): PagerSlot[] {
	const total = Math.max( 1, page_count );
	const page = Math.min( total, Math.max( 1, current ) );
	const slots: PagerSlot[] = [];

	const add_page = ( number: number ): void => {
		slots.push( { kind: 'page', page: number, current: number === page } );
	};
	const add_ellipsis = ( key: string ): void => {
		slots.push( { kind: 'ellipsis', key } );
	};

	if ( total <= window_size + 2 ) {
		for ( let number = 1; number <= total; number++ ) {
			add_page( number );
		}

		return slots;
	}

	if ( page <= window_size ) {
		for ( let number = 1; number <= window_size; number++ ) {
			add_page( number );
		}

		add_ellipsis( 'end' );
		add_page( total );
		return slots;
	}

	if ( page > total - window_size + 1 ) {
		add_page( 1 );
		add_ellipsis( 'start' );

		for ( let number = total - window_size + 1; number <= total; number++ ) {
			add_page( number );
		}

		return slots;
	}

	add_page( 1 );
	add_ellipsis( 'start' );
	const start = page - 2;
	const end = start + window_size - 1;

	for ( let number = start; number <= end; number++ ) {
		add_page( number );
	}

	add_ellipsis( 'end' );
	add_page( total );

	return slots;
}

export function visible_pager_slots( current: number, page_count: number, known_pages: ReadonlySet<number> ): PagerSlot[] {
	const slots = pager_slots( current, page_count );
	if ( known_pages.size === 0 ) { return slots; }

	const visible: PagerSlot[] = [];
	for ( const slot of slots ) {
		if ( slot.kind === 'page' ) {
			if ( known_pages.has( slot.page ) || slot.current ) {
				visible.push( slot );
			}

			continue;
		}
		const previous = visible[ visible.length - 1 ];

		if ( previous?.kind === 'ellipsis' ) { continue; }
		
		visible.push( slot );
	}

	while ( visible[ 0 ]?.kind === 'ellipsis' ) {
		visible.shift();
	}
	while ( visible[ visible.length - 1 ]?.kind === 'ellipsis' ) {
		visible.pop();
	}

	return visible;
}
