<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\CategoryPage;
use MediaWiki\Page\Hook\CategoryPageViewHook;
use MediaWiki\Parser\Hook\ParserFirstCallInitHook;
use ReflectionProperty;

class Hooks implements BeforePageDisplayHook, CategoryPageViewHook, ParserFirstCallInitHook {

	/** @inheritDoc */
	public function onCategoryPageView( $catpage ) {
		$property = new ReflectionProperty( CategoryPage::class, 'mCategoryViewerClass' );
		$property->setValue( $catpage, AzCategoryViewer::class );
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( $title === null || !$title->inNamespace( NS_CATEGORY ) ) {
			return;
		}

		if ( $out->getRequest()->getVal( 'action', 'view' ) !== 'view' ) {
			return;
		}

		$out->addModuleStyles( [ AzIndex::STYLE_MODULE ] );
		$out->addModules( [ AzIndex::APP_MODULE ] );
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook(
			'advancedcategories',
			[ ParserFunctions::class, 'advancedcategories' ]
		);
	}

}
