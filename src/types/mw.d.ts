export type CategoryThumbnail = {
	url: string;
	width: number;
	height: number;
};

export type CargoValue = string | string[] | null;

export type CategoryColumn = {
	key: string;
	label: string;
};

export type CategoryPage = {
	page_id: number;
	title: string;
	display_title: string;
	url: string;
	letter: string;
	is_redirect: boolean;
	thumbnail: CategoryThumbnail | null;
	description: string | null;
	cargo: Record<string, CargoValue>;
};

export type CategoryPageLink = {
	page: number;
	url: string | null;
};

export type CategoryPagination = {
	total: number;
	limit: number;
	start: number;
	end: number;
	page: number;
	page_count: number;
	precise: boolean;
	first_url: string | null;
	prev_url: string | null;
	next_url: string | null;
	last_url: string | null;
	page_links: CategoryPageLink[];
};

export type CategoryLetter = {
	letter: string;
	url: string;
	page: number;
	current: boolean;
};

export type AdvancedCategoriesConfig = {
	letters: CategoryLetter[];
	pages: CategoryPage[];
	pagination: CategoryPagination;
	columns: CategoryColumn[];
};

declare global {
	interface MediaWikiConfigMap {
		wgAdvancedCategories?: AdvancedCategoriesConfig;
	}
}

export {};
