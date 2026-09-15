<?php

namespace MediaWiki\Extension\AdvancedCategories;

use CargoSQLQuery;
use CargoUtils;
use Exception;
use MediaWiki\Page\PageProps;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;

class CargoLookup {

	public const PROP = 'advancedcategories-cargo';
	public const MAX_FIELDS = 8;

	private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	public function __construct(
		private readonly PageProps $page_props,
		private readonly WANObjectCache $wan_cache,
	) {
	}

	/**
	 * Whether the Cargo extension is installed and can therefore be queried.
	 */
	public function is_available(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'Cargo' );
	}

	/**
	 * @param string[] $args
	 * @return array{table: ?string, fields: list<array{key: string, label: string}>, warnings: list<array{key: string, params: list<string>}>}
	 */
	public function parse_args( array $args ): array {
		$warnings = [];
		$table = null;
		$fields_str = '';

		foreach ( $args as $arg ) {
			$arg = trim( (string)$arg );
			if ( $arg === '' || !str_contains( $arg, '=' ) ) {
				continue;
			}
			[ $name, $value ] = explode( '=', $arg, 2 );
			$name = strtolower( trim( $name ) );
			$value = trim( $value );
			if ( $name === 'table' || $name === 'tables' ) {
				$table = $value;
				continue;
			}
			if ( $name === 'fields' ) {
				$fields_str = $value;
			}
		}

		if ( $table === null || $table === '' || $fields_str === '' ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-missing-params',
				'params' => [],
			];

			return [
				'table' => null,
				'fields' => [],
				'warnings' => $warnings,
			];
		}

		if ( preg_match( self::IDENT, $table ) !== 1 ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-invalid-table',
				'params' => [ $table ],
			];

			return [
				'table' => null,
				'fields' => [],
				'warnings' => $warnings,
			];
		}

		$fields = [];
		$seen = [];
		$raw_count = 0;
		foreach ( explode( ',', $fields_str ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			$raw_count++;
			$key = $part;
			$label = '';
			if ( str_contains( $part, '=' ) ) {
				[ $key, $label ] = explode( '=', $part, 2 );
				$key = trim( $key );
				$label = trim( $label );
			}
			if ( preg_match( self::IDENT, $key ) !== 1 ) {
				$warnings[] = [
					'key' => 'advancedcategories-cargo-invalid-field',
					'params' => [ $key ],
				];
				continue;
			}
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			if ( count( $fields ) >= self::MAX_FIELDS ) {
				continue;
			}
			$seen[$key] = true;
			if ( $label === '' ) {
				$label = ucfirst( str_replace( '_', ' ', $key ) );
			}
			$fields[] = [
				'key' => $key,
				'label' => $label,
			];
		}

		if ( $raw_count > self::MAX_FIELDS ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-too-many-fields',
				'params' => [ (string)self::MAX_FIELDS ],
			];
		}

		if ( $fields === [] ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-no-fields',
				'params' => [],
			];
		}

		return [
			'table' => $table,
			'fields' => $fields,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param array{table: string, fields: list<array{key: string, label: string}>} $declaration
	 * @return array{table: string, fields: list<array{key: string, label: string}>, warnings: list<array{key: string, params: list<string>}>}
	 */
	public function validate_declaration( array $declaration ): array {
		$warnings = [];
		$table = $declaration['table'];
		$fields = $declaration['fields'];

		if ( !$this->is_available() ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-not-loaded',
				'params' => [],
			];

			return [
				'table' => $table,
				'fields' => [],
				'warnings' => $warnings,
			];
		}

		$meta = $this->field_meta( $table );
		if ( $meta === [] ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-unknown-table',
				'params' => [ $table ],
			];

			return [
				'table' => $table,
				'fields' => [],
				'warnings' => $warnings,
			];
		}

		$valid = [];
		foreach ( $fields as $field ) {
			$key = $field['key'];
			if ( !isset( $meta[$key] ) ) {
				$warnings[] = [
					'key' => 'advancedcategories-cargo-unknown-field',
					'params' => [ $key, $table ],
				];
				continue;
			}
			$valid[] = $field;
		}

		if ( $valid === [] ) {
			$warnings[] = [
				'key' => 'advancedcategories-cargo-no-fields',
				'params' => [],
			];
		}

		return [
			'table' => $table,
			'fields' => $valid,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param Title $title
	 * @return array{table: string, fields: list<array{key: string, label: string}>}|null
	 */
	public function declaration_for_title( Title $title ): ?array {
		$props = $this->page_props->getProperties( [ $title ], [ self::PROP ] );
		$page_id = $title->getArticleID();
		$raw = $props[$page_id] ?? null;
		if ( is_array( $raw ) ) {
			$raw = $raw[self::PROP] ?? null;
		}
		if ( !is_string( $raw ) || $raw === '' ) {
			return null;
		}

		$data = json_decode( $raw, true );
		if ( !is_array( $data ) ) {
			return null;
		}

		$table = $data['table'] ?? null;
		$fields = $data['fields'] ?? null;
		if ( !is_string( $table ) || preg_match( self::IDENT, $table ) !== 1 || !is_array( $fields ) ) {
			return null;
		}

		$parsed_fields = [];
		foreach ( $fields as $field ) {
			if ( !is_array( $field ) ) {
				continue;
			}
			$key = $field['key'] ?? null;
			$label = $field['label'] ?? null;
			if ( !is_string( $key ) || preg_match( self::IDENT, $key ) !== 1 ) {
				continue;
			}
			if ( !is_string( $label ) || $label === '' ) {
				$label = ucfirst( str_replace( '_', ' ', $key ) );
			}
			$parsed_fields[] = [
				'key' => $key,
				'label' => $label,
			];
			if ( count( $parsed_fields ) >= self::MAX_FIELDS ) {
				break;
			}
		}

		if ( $parsed_fields === [] ) {
			return null;
		}

		$validated = $this->validate_declaration( [
			'table' => $table,
			'fields' => $parsed_fields,
		] );
		if ( $validated['fields'] === [] ) {
			return null;
		}

		return [
			'table' => $validated['table'],
			'fields' => $validated['fields'],
		];
	}

	/**
	 * @param string $table
	 * @param list<string> $field_keys
	 * @param list<int> $page_ids
	 * @return array<int, array<string, string|list<string>|null>>
	 */
	public function values_for_page_ids( string $table, array $field_keys, array $page_ids ): array {
		if ( !$this->is_available() || preg_match( self::IDENT, $table ) !== 1 ) {
			return [];
		}

		$ids = [];
		foreach ( $page_ids as $page_id ) {
			$page_id = (int)$page_id;
			if ( $page_id > 0 ) {
				$ids[$page_id] = $page_id;
			}
		}
		$ids = array_values( $ids );
		if ( $ids === [] || $field_keys === [] ) {
			return [];
		}

		$meta = $this->field_meta( $table );
		$keys = [];
		foreach ( $field_keys as $key ) {
			if ( !is_string( $key ) || !isset( $meta[$key] ) ) {
				continue;
			}
			$keys[] = $key;
		}
		if ( $keys === [] ) {
			return [];
		}

		sort( $ids );
		$cache_key = $this->wan_cache->makeKey(
			'advancedcategories',
			'cargo-rows',
			$table,
			implode( ',', $keys ),
			md5( implode( ',', $ids ) )
		);

		$rows = $this->wan_cache->getWithSetCallback(
			$cache_key,
			WANObjectCache::TTL_HOUR,
			function () use ( $table, $keys, $ids, $meta ): array {
				return $this->query_rows( $table, $keys, $ids, $meta );
			}
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param string $table
	 * @param list<string> $keys
	 * @param list<int> $ids
	 * @param array<string, array{list: bool, delimiter: string}> $meta
	 * @return array<int, array<string, string|list<string>|null>>
	 */
	private function query_rows( string $table, array $keys, array $ids, array $meta ): array {
		$fields_str = '_pageID=page_id';
		foreach ( $keys as $key ) {
			$fields_str .= ',' . $key;
		}
		$where = '_pageID IN (' . implode( ',', $ids ) . ')';

		try {
			$query = CargoSQLQuery::newFromValues(
				$table,
				$fields_str,
				$where,
				'',
				'',
				'',
				'',
				(string)count( $ids ),
				''
			);
			$result = $query->run();
		} catch ( Exception ) {
			return [];
		}

		if ( !is_array( $result ) ) {
			return [];
		}

		$rows = [];
		foreach ( $result as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$page_id = (int)( $row['page_id'] ?? 0 );
			if ( $page_id < 1 ) {
				continue;
			}
			$values = [];
			foreach ( $keys as $key ) {
				$values[$key] = $this->normalize_value( $row[$key] ?? null, $meta[$key] );
			}
			$rows[$page_id] = $values;
		}

		return $rows;
	}

	/**
	 * @param mixed $raw
	 * @param array{list: bool, delimiter: string} $meta
	 * @return string|list<string>|null
	 */
	private function normalize_value( mixed $raw, array $meta ): string|array|null {
		if ( $raw === null ) {
			return null;
		}
		$text = html_entity_decode( trim( (string)$raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $text === '' ) {
			return null;
		}
		if ( !$meta['list'] ) {
			return $text;
		}

		$delimiter = $meta['delimiter'] !== '' ? $meta['delimiter'] : ',';
		$parts = [];
		foreach ( explode( $delimiter, $text ) as $part ) {
			$part = trim( $part );
			if ( $part !== '' ) {
				$parts[] = $part;
			}
		}

		return $parts === [] ? null : $parts;
	}

	/**
	 * @return array<string, array{list: bool, delimiter: string}>
	 */
	private function field_meta( string $table ): array {
		try {
			$schemas = CargoUtils::getTableSchemas( [ $table ] );
		} catch ( Exception ) {
			return [];
		}

		$schema = $schemas[$table] ?? null;
		if (
			!is_object( $schema ) ||
			!isset( $schema->mFieldDescriptions ) ||
			!is_array( $schema->mFieldDescriptions )
		) {
			return [];
		}

		$meta = [];
		foreach ( $schema->mFieldDescriptions as $name => $description ) {
			if ( !is_string( $name ) ) {
				continue;
			}
			$list = is_object( $description ) && !empty( $description->mIsList );
			$delimiter = ',';
			if (
				is_object( $description ) &&
				isset( $description->mDelimiter ) &&
				is_string( $description->mDelimiter ) &&
				$description->mDelimiter !== ''
			) {
				$delimiter = $description->mDelimiter;
			}
			$meta[$name] = [
				'list' => $list,
				'delimiter' => $delimiter,
			];
		}

		return $meta;
	}

}
