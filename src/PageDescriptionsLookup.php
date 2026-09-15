<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Page\PageProps;
use MediaWiki\Title\Title;

class PageDescriptionsLookup {

	private const PROP_SHORT = 'shortdesc';
	private const PROP_WIKIBASE = 'wikibase-shortdesc';

	public function __construct(
		private readonly PageProps $page_props,
	) {
	}

	/**
	 * @param Title[] $titles
	 * @return array<int, string>
	 */
	public function descriptions_for_titles( array $titles ): array {
		if ( $titles === [] ) {
			return [];
		}

		$descriptions = [];
		foreach ( array_chunk( $titles, 500 ) as $chunk ) {
			$props = $this->page_props->getProperties( $chunk, [ self::PROP_SHORT, self::PROP_WIKIBASE ] );
			foreach ( $props as $page_id => $values ) {
				$text = null;
				if ( is_array( $values ) ) {
					$text = $values[self::PROP_SHORT] ?? $values[self::PROP_WIKIBASE] ?? null;
				} elseif ( is_string( $values ) ) {
					$text = $values;
				}
				if ( !is_string( $text ) ) {
					continue;
				}

				$text = trim( $text );
				if ( $text !== '' ) {
					$descriptions[(int)$page_id] = $text;
				}
			}
		}

		return $descriptions;
	}

}
