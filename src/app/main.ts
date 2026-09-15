import { createApp } from 'vue';
import CategoryApp from '../components/CategoryApp.vue';
import type { AdvancedCategoriesConfig } from '../types/mw';

function read_config(): AdvancedCategoriesConfig | null {
	const config = mw.config.get( 'wgAdvancedCategories' ) as AdvancedCategoriesConfig | null;
	if (
		!config ||
		!Array.isArray( config.letters ) ||
		!Array.isArray( config.pages ) ||
		!config.pagination
	) {
		return null;
	}

	return {
		...config,
		columns: Array.isArray( config.columns ) ? config.columns : [],
		pages: config.pages.map( ( page ) => ( {
			...page,
			description: typeof page.description === 'string' ? page.description : null,
			cargo: page.cargo && typeof page.cargo === 'object' ? page.cargo : {},
		} ) ),
	};
}

function mount(): void {
	const config = read_config();
	const mount_root = document.getElementById( 'advancedcategories-pages-app' );
	if ( !config || !mount_root ) {
		return;
	}

	createApp( CategoryApp, { config } ).mount( mount_root );
}

mount();
