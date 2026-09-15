import type { CategoryLetter } from '../types/mw';

export const HASH_BUCKET = '#';
export const OTHER_BUCKET = 'OTHER';

export function letter_id( letter: string ): string {
	return 'advancedcategories-letter-' + encodeURIComponent( letter );
}

export function normalize_letter( letter: string ): string {
	return letter.replace( /\u00A0/g, ' ' ).trim();
}

export function bucket_letter( letter: string ): string {
	const normalized = normalize_letter( letter ).toUpperCase();
	if ( normalized === '' || normalized === HASH_BUCKET ) { return HASH_BUCKET; }
	if ( normalized === OTHER_BUCKET ) { return OTHER_BUCKET; }
	if ( /^[A-Z]$/.test( normalized ) ) { return normalized; }
	if ( /^[0-9]$/.test( normalized ) ) { return HASH_BUCKET; }
	if ( normalized.length === 1 && normalized.charCodeAt( 0 ) < 128 ) { return HASH_BUCKET; }

	return OTHER_BUCKET;
}

export type IndexLetter = {
	letter: string;
	present: boolean;
	id: string;
	label: string;
	href: string | null;
	page: number | null;
	current: boolean;
};

export function index_letters( present_letters: CategoryLetter[] ): IndexLetter[] {
	const by_bucket = new Map<string, { href: string; page: number; current: boolean }>();
	for ( const item of present_letters ) {
		const bucket = bucket_letter( item.letter );
		const existing = by_bucket.get( bucket );

		if ( !item.url ) { continue; }

		if ( !existing || item.page < existing.page ) {
			by_bucket.set( bucket, {
				href: item.url,
				page: item.page,
				current: !!item.current,
			} );

			continue;
		}

		if ( item.current ) {
			existing.current = true;
		}
	}

	const order = [ HASH_BUCKET ].concat( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split( '' ) );
	if ( by_bucket.has( OTHER_BUCKET ) ) {
		order.push( OTHER_BUCKET );
	}

	return order.map( ( letter ) => {
		const found = by_bucket.get( letter );
		return {
			letter,
			present: found !== undefined,
			id: letter_id( letter ),
			label: letter,
			href: found !== undefined ? found.href : null,
			page: found !== undefined ? found.page : null,
			current: found !== undefined ? found.current : false
		};
	} );
}

export function msg( key: string, ...args: string[] ): string {
	return mw.message( key, ...args ).text();
}
