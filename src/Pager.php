<?php

namespace MediaWiki\Extension\AdvancedCategories;

class Pager {

	private const WINDOW_SIZE = 6;

	/**
	 * @return list<array{kind: 'page', page: int, current: bool}|array{kind: 'ellipsis', key: string}>
	 */
	public static function slots( int $current, int $page_count ): array {
		$total = max( 1, $page_count );
		$page = min( $total, max( 1, $current ) );
		$slots = [];

		$add_page = static function ( int $number ) use ( &$slots, $page ): void {
			$slots[] = [
				'kind' => 'page',
				'page' => $number,
				'current' => $number === $page,
			];
		};
		$add_ellipsis = static function ( string $key ) use ( &$slots ): void {
			$slots[] = [
				'kind' => 'ellipsis',
				'key' => $key,
			];
		};

		if ( $total <= self::WINDOW_SIZE + 2 ) {
			for ( $number = 1; $number <= $total; $number++ ) {
				$add_page( $number );
			}

			return $slots;
		}

		if ( $page <= self::WINDOW_SIZE ) {
			for ( $number = 1; $number <= self::WINDOW_SIZE; $number++ ) {
				$add_page( $number );
			}
			$add_ellipsis( 'end' );
			$add_page( $total );

			return $slots;
		}

		if ( $page > $total - self::WINDOW_SIZE + 1 ) {
			$add_page( 1 );
			$add_ellipsis( 'start' );
			for ( $number = $total - self::WINDOW_SIZE + 1; $number <= $total; $number++ ) {
				$add_page( $number );
			}

			return $slots;
		}

		$add_page( 1 );
		$add_ellipsis( 'start' );
		$start = $page - 2;
		$end = $start + self::WINDOW_SIZE - 1;
		for ( $number = $start; $number <= $end; $number++ ) {
			$add_page( $number );
		}
		$add_ellipsis( 'end' );
		$add_page( $total );

		return $slots;
	}

	/**
	 * @param array<int, true> $known_pages
	 * @return list<array{kind: 'page', page: int, current: bool}|array{kind: 'ellipsis', key: string}>
	 */
	public static function visible_slots( int $current, int $page_count, array $known_pages ): array {
		$slots = self::slots( $current, $page_count );
		if ( $known_pages === [] ) {
			return $slots;
		}

		$visible = [];
		foreach ( $slots as $slot ) {
			if ( $slot['kind'] === 'page' ) {
				if ( isset( $known_pages[ $slot['page'] ] ) || $slot['current'] ) {
					$visible[] = $slot;
				}
				continue;
			}

			$previous = $visible === [] ? null : $visible[ array_key_last( $visible ) ];
			if ( ( $previous['kind'] ?? null ) === 'ellipsis' ) {
				continue;
			}
			$visible[] = $slot;
		}

		while ( $visible !== [] && $visible[0]['kind'] === 'ellipsis' ) {
			array_shift( $visible );
		}
		while ( $visible !== [] && $visible[ array_key_last( $visible ) ]['kind'] === 'ellipsis' ) {
			array_pop( $visible );
		}

		return array_values( $visible );
	}

}
