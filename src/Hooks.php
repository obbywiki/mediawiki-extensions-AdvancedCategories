<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\CategoryPage;
use MediaWiki\Page\Hook\CategoryPageViewHook;
use MediaWiki\Page\PageProps;
use MediaWiki\Parser\Hook\ParserFirstCallInitHook;
use MediaWiki\Title\Title;
use ReflectionProperty;

class Hooks implements BeforePageDisplayHook, CategoryPageViewHook, GetDoubleUnderscoreIDsHook, ParserFirstCallInitHook {

	public const STOCK_MAGIC = 'noadvancedcategories';

	public function __construct(
		private readonly PageProps $page_props,
	) {
	}

	/** @inheritDoc */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = self::STOCK_MAGIC;
	}

	/** @inheritDoc */
	public function onCategoryPageView( $catpage ) {
		if ( $this->uses_stock_rendering( $catpage->getTitle() ) ) {
			return;
		}

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

		if ( $this->uses_stock_rendering( $title ) ) {
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

	private function uses_stock_rendering( Title $title ): bool {
		$page_id = $title->getArticleID();
		if ( $page_id <= 0 ) {
			return false;
		}

		$props = $this->page_props->getProperties( [ $title ], self::STOCK_MAGIC );
		$value = $props[$page_id] ?? null;
		if ( is_array( $value ) ) {
			$value = $value[self::STOCK_MAGIC] ?? null;
		}

		return $value !== null;
	}

}
