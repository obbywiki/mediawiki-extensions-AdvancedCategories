<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Collation\Collation;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Language\ILanguageConverter;

class AzIndex {

	public const APP_MODULE = 'ext.advancedCategories.app';
	public const STYLE_MODULE = 'ext.advancedCategories.styles';
	public const HASH_BUCKET = '#';
	public const OTHER_BUCKET = 'OTHER';

	/**
	 * @param string[] $present_letters
	 */
	public function render( array $present_letters, IContextSource $context ): string {
		$present = [];
		foreach ( $present_letters as $letter ) {
			$letter = $this->normalize_letter( $letter );
			if ( $letter !== '' ) {
				$present[$letter] = true;
			}
		}

		$alpha_letters = range( 'A', 'Z' );
		$extra_letters = [];
		foreach ( array_keys( $present ) as $letter ) {
			if ( !in_array( $letter, $alpha_letters, true ) ) {
				$extra_letters[] = $letter;
			}
		}
		sort( $extra_letters, SORT_STRING );
		$index_letters = array_merge( $extra_letters, $alpha_letters );

		$items = [];
		foreach ( $index_letters as $letter ) {
			$label = $letter === ' ' ? "\u{00A0}" : $letter;
			if ( isset( $present[$letter] ) ) {
				$items[] = Html::element(
					'a',
					[
						'class' => 'advancedcategories-az-index__letter',
						'href' => '#' . self::letter_id( $letter ),
					],
					$label
				);
			} else {
				$items[] = Html::element(
					'span',
					[ 'class' => 'advancedcategories-az-index__letter advancedcategories-az-index__letter--empty' ],
					$label
				);
			}
		}

		$heading = Html::element(
			'span',
			[ 'class' => 'advancedcategories-az-index__label' ],
			$context->msg( 'advancedcategories-az-index' )->text()
		);

		return Html::rawElement(
			'nav',
			[
				'class' => 'advancedcategories-az-index',
				'aria-label' => $context->msg( 'advancedcategories-az-index-aria' )->text(),
			],
			$heading . Html::rawElement(
				'div',
				[ 'class' => 'advancedcategories-az-index__letters' ],
				implode( '', $items )
			)
		);
	}

	public function annotate_groups( string $html ): string {
		$annotated = preg_replace_callback(
			'/(<div class="mw-category-group">)(\s*<h3>)(.*?)(<\/h3>)/s',
			function ( array $match ): string {
				$letter = $this->normalize_letter(
					html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				);
				$id = htmlspecialchars( self::letter_id( $letter ), ENT_QUOTES, 'UTF-8' );

				return '<div class="mw-category-group" id="' . $id . '">'
					. $match[2] . $match[3] . $match[4];
			},
			$html
		);

		return is_string( $annotated ) ? $annotated : $html;
	}

	/**
	 * @return string[]
	 */
	public function collect_letters( string $html ): array {
		if ( !preg_match_all( '/id="advancedcategories-letter-([^"]+)"/', $html, $matches ) ) {
			return [];
		}

		$letters = [];
		foreach ( $matches[1] as $encoded ) {
			$letter = $this->normalize_letter( rawurldecode( $encoded ) );
			if ( $letter !== '' ) {
				$letters[$letter] = true;
			}
		}

		return array_keys( $letters );
	}

	public static function letter_id( string $letter ): string {
		return 'advancedcategories-letter-' . rawurlencode( $letter );
	}

	public function normalize_letter( string $letter ): string {
		$letter = str_replace( "\u{00A0}", ' ', $letter );

		return trim( $letter );
	}

	public function letter_from_sortkey(
		string $sortkey,
		Collation $collation,
		ILanguageConverter $converter
	): string {
		return $this->normalize_letter( $converter->convert( $collation->getFirstLetter( $sortkey ) ) );
	}

	public function bucket( string $letter ): string {
		$letter = mb_strtoupper( $this->normalize_letter( $letter ), 'UTF-8' );
		if ( $letter === '' || $letter === self::HASH_BUCKET ) {
			return self::HASH_BUCKET;
		}
		if ( $letter === self::OTHER_BUCKET ) {
			return self::OTHER_BUCKET;
		}
		if ( preg_match( '/^[A-Z]$/D', $letter ) ) {
			return $letter;
		}
		if ( preg_match( '/^[0-9]$/D', $letter ) ) {
			return self::HASH_BUCKET;
		}
		if ( strlen( $letter ) === 1 && ord( $letter ) < 128 ) {
			return self::HASH_BUCKET;
		}

		return self::OTHER_BUCKET;
	}

	/**
	 * @param string[] ...$letter_sets
	 * @return string[]
	 */
	public function merge_letters( array ...$letter_sets ): array {
		$present = [];
		foreach ( $letter_sets as $set ) {
			foreach ( $set as $letter ) {
				$letter = $this->normalize_letter( (string)$letter );
				if ( $letter !== '' ) {
					$present[$letter] = true;
				}
			}
		}

		return array_keys( $present );
	}

}
