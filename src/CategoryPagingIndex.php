<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Collation\CollationFactory;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\LinksUpdate\CategoryLinksTable;
use MediaWiki\Language\LanguageConverterFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class CategoryPagingIndex {

	/** Skip a full page-start scan above this member count. */
	private const MAX_INDEXED_MEMBERS = 25000;

	public function __construct(
		private readonly IConnectionProvider $connection_provider,
		private readonly WANObjectCache $wan_cache,
		private readonly Config $config,
		private readonly AzIndex $az_index,
		private readonly CollationFactory $collation_factory,
		private readonly LanguageConverterFactory $language_converter_factory,
	) {
	}

	public function page_member_count( PageIdentity $category ): int {
		$count = $this->wan_cache->getWithSetCallback(
			$this->wan_cache->makeKey(
				'advancedcategories',
				'page-member-count',
				$category->getNamespace(),
				md5( $category->getDBkey() )
			),
			WANObjectCache::TTL_HOUR,
			function () use ( $category ): int {
				return (int)$this->dbr()->newSelectQueryBuilder()
					->select( 'COUNT(*)' )
					->from( 'page' )
					->join( 'categorylinks', null, [ 'cl_from = page_id' ] )
					->join( 'linktarget', null, 'cl_target_id = lt_id' )
					->where( [
						'lt_title' => $category->getDBkey(),
						'lt_namespace' => NS_CATEGORY,
						'cl_type' => 'page',
					] )
					->caller( __METHOD__ )
					->fetchField();
			}
		);

		return max( 0, (int)$count );
	}

	/**
	 * Human sortkeys that start each page. Index 0 is the first page (empty from).
	 *
	 * @return string[]|null Null when the category is too large to index in one scan.
	 */
	public function starts( PageIdentity $category, int $limit, int $member_count ): ?array {
		$index = $this->page_index( $category, $limit, $member_count );

		return $index === null ? null : $index['starts'];
	}

	/**
	 * Cached page-start sortkeys and the first sortkey/page for each letter.
	 *
	 * @return array{starts: string[], letter_starts: array<string, array{page: int, from: string}>}|null
	 *   Null when the category is too large to index in one scan.
	 */
	public function page_index( PageIdentity $category, int $limit, int $member_count ): ?array {
		if ( $limit < 1 || $member_count < 1 ) {
			return [
				'starts' => [ '' ],
				'letter_starts' => [],
			];
		}
		if ( $member_count <= $limit ) {
			return [
				'starts' => [ '' ],
				'letter_starts' => [],
			];
		}
		if ( $member_count > self::MAX_INDEXED_MEMBERS ) {
			return null;
		}

		$cache_key = $this->cache_key( 'paging-index-2', $category, $limit, $member_count );
		$index = $this->wan_cache->getWithSetCallback(
			$cache_key,
			WANObjectCache::TTL_HOUR,
			function () use ( $category, $limit ): array {
				return $this->scan_index( $category, $limit );
			}
		);

		if (
			!is_array( $index ) ||
			!isset( $index['starts'] ) ||
			!is_array( $index['starts'] ) ||
			$index['starts'] === []
		) {
			return [
				'starts' => [ '' ],
				'letter_starts' => [],
			];
		}

		$letter_starts = [];
		if ( isset( $index['letter_starts'] ) && is_array( $index['letter_starts'] ) ) {
			foreach ( $index['letter_starts'] as $letter => $info ) {
				$letter = $this->az_index->normalize_letter( (string)$letter );
				if ( $letter === '' || !is_array( $info ) ) {
					continue;
				}
				$page = (int)( $info['page'] ?? 0 );
				$from = (string)( $info['from'] ?? '' );
				if ( $page > 0 ) {
					$letter_starts[$letter] = [
						'page' => $page,
						'from' => $from,
					];
				}
			}
		}

		return [
			'starts' => array_values( $index['starts'] ),
			'letter_starts' => $letter_starts,
		];
	}

	public function last_start( PageIdentity $category, int $limit, int $member_count ): ?string {
		$starts = $this->starts( $category, $limit, $member_count );
		if ( $starts !== null ) {
			$last = end( $starts );

			return is_string( $last ) ? $last : '';
		}

		$page_count = (int)ceil( $member_count / $limit );
		if ( $page_count <= 1 ) {
			return '';
		}

		$start = $this->wan_cache->getWithSetCallback(
			$this->cache_key( 'paging-last', $category, $limit, $member_count ),
			WANObjectCache::TTL_HOUR,
			function () use ( $category, $limit, $page_count ): ?string {
				return $this->fetch_start_at_offset( $category, ( $page_count - 1 ) * $limit );
			}
		);

		return is_string( $start ) ? $start : null;
	}

	/**
	 * @return array{starts: string[], letter_starts: array<string, array{page: int, from: string}>}
	 */
	private function scan_index( PageIdentity $category, int $limit ): array {
		$starts = [ '' ];
		$letter_starts = [];
		$n = 0;
		$collation = $this->collation_factory->getCategoryCollation();
		$converter = $this->language_converter_factory->getLanguageConverter();
		$res = $this->member_query( $this->dbr(), $category )->caller( __METHOD__ )->fetchResultSet();

		foreach ( $res as $row ) {
			$sortkey = $this->human_sortkey( $row );
			if ( $n > 0 && $n % $limit === 0 && $sortkey !== null ) {
				$starts[] = $sortkey;
			}
			if ( $sortkey !== null ) {
				$letter = $this->az_index->letter_from_sortkey( $sortkey, $collation, $converter );
				if ( $letter !== '' && !isset( $letter_starts[$letter] ) ) {
					$letter_starts[$letter] = [
						'page' => count( $starts ),
						'from' => $sortkey,
					];
				}
			}
			$n++;
		}

		return [
			'starts' => $starts,
			'letter_starts' => $letter_starts,
		];
	}

	private function fetch_start_at_offset( PageIdentity $category, int $offset ): ?string {
		if ( $offset < 1 ) {
			return '';
		}

		$row = $this->member_query( $this->dbr(), $category )
			->offset( $offset )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? $this->human_sortkey( $row ) : null;
	}

	private function member_query( IReadableDatabase $dbr, PageIdentity $category ): SelectQueryBuilder {
		return $dbr->newSelectQueryBuilder()
			->select( [
				'page_namespace',
				'page_title',
				'cl_sortkey_prefix',
			] )
			->from( 'page' )
			->join( 'categorylinks', null, [ 'cl_from = page_id' ] )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [
				'lt_title' => $category->getDBkey(),
				'lt_namespace' => NS_CATEGORY,
				'cl_type' => 'page',
			] )
			->useIndex( [ 'categorylinks' => 'cl_sortkey_id' ] )
			->orderBy( 'cl_sortkey' );
	}

	private function human_sortkey( object $row ): ?string {
		$title = Title::makeTitleSafe( (int)$row->page_namespace, (string)$row->page_title );
		if ( $title === null ) {
			return null;
		}

		return $title->getCategorySortkey( (string)$row->cl_sortkey_prefix );
	}

	private function cache_key( string $kind, PageIdentity $category, int $limit, int $member_count ): string {
		return $this->wan_cache->makeKey(
			'advancedcategories',
			$kind,
			$category->getNamespace(),
			md5( $category->getDBkey() ),
			(string)$this->config->get( MainConfigNames::CategoryCollation ),
			$limit,
			$member_count
		);
	}

	private function dbr(): IReadableDatabase {
		return $this->connection_provider->getReplicaDatabase( CategoryLinksTable::VIRTUAL_DOMAIN );
	}

}
