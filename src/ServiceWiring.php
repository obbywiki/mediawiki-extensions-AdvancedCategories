<?php

use MediaWiki\Extension\AdvancedCategories\AzIndex;
use MediaWiki\Extension\AdvancedCategories\CargoLookup;
use MediaWiki\Extension\AdvancedCategories\CategoryPagingIndex;
use MediaWiki\Extension\AdvancedCategories\PageDescriptionsLookup;
use MediaWiki\Extension\AdvancedCategories\PageImagesLookup;
use MediaWiki\MediaWikiServices;

return [
	'AdvancedCategories.AzIndex' => static function ( MediaWikiServices $services ): AzIndex {
		return new AzIndex();
	},
	'AdvancedCategories.PageImagesLookup' => static function ( MediaWikiServices $services ): PageImagesLookup {
		return new PageImagesLookup(
			$services->getPageProps(),
			$services->getRepoGroup()
		);
	},
	'AdvancedCategories.PageDescriptionsLookup' => static function ( MediaWikiServices $services ): PageDescriptionsLookup {
		return new PageDescriptionsLookup(
			$services->getPageProps()
		);
	},
	'AdvancedCategories.CargoLookup' => static function ( MediaWikiServices $services ): CargoLookup {
		return new CargoLookup(
			$services->getPageProps(),
			$services->getMainWANObjectCache()
		);
	},
	'AdvancedCategories.CategoryPagingIndex' => static function ( MediaWikiServices $services ): CategoryPagingIndex {
		return new CategoryPagingIndex(
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache(),
			$services->getMainConfig(),
			$services->get( 'AdvancedCategories.AzIndex' ),
			$services->getCollationFactory(),
			$services->getLanguageConverterFactory()
		);
	},
];
