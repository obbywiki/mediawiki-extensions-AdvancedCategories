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

	return config;
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
