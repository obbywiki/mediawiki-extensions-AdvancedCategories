<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;

class ParserFunctions {

	/**
	 * @param Parser $parser
	 * @param string ...$args
	 */
	public static function advancedcategories( Parser $parser, ...$args ): string {
		$parser->addTrackingCategory( 'advancedcategories-tracking-category' );

		/** @var CargoLookup $lookup */
		$lookup = MediaWikiServices::getInstance()->get( 'AdvancedCategories.CargoLookup' );
		$parsed = $lookup->parse_args( $args );
		self::emit_warnings( $parser, $parsed['warnings'] );
		if ( $parsed['table'] === null || $parsed['fields'] === [] ) {
			return '';
		}

		$validated = $lookup->validate_declaration( [
			'table' => $parsed['table'],
			'fields' => $parsed['fields'],
		] );
		self::emit_warnings( $parser, $validated['warnings'] );
		if ( $validated['fields'] === [] ) {
			return '';
		}

		$json = FormatJson::encode( [
			'table' => $validated['table'],
			'fields' => $validated['fields'],
		], false, FormatJson::ALL_OK );
		if ( !is_string( $json ) || $json === '' ) {
			return '';
		}

		$parser->getOutput()->setUnsortedPageProperty( CargoLookup::PROP, $json );

		return '';
	}

	/**
	 * @param list<array{key: string, params: list<string>}> $warnings
	 */
	private static function emit_warnings( Parser $parser, array $warnings ): void {
		foreach ( $warnings as $warning ) {
			$parser->getOutput()->addWarningMsg( $warning['key'], ...$warning['params'] );
		}
	}

}
