<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Category\CategoryViewer;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\Title;

class AzCategoryViewer extends CategoryViewer {

	/** @var array<int, array{title: Title, letter: string, is_redirect: bool, sortkey: string}> */
	private array $page_members = [];

	/** @var array{total: int, limit: int, start: int, end: int, page: int, page_count: int, precise: bool, first_url: ?string, prev_url: ?string, next_url: ?string, last_url: ?string, page_links: list<array{page: int, url: ?string}>}|null */
	private ?array $pagination = null;

	/** @inheritDoc */
	protected function clearCategoryState() {
		$this->page_members = [];
		$this->pagination = null;
		parent::clearCategoryState();
	}

	/** @inheritDoc */
	public function addPage(
		PageReference $page,
		string $sortkey,
		int $pageLength,
		bool $isRedirect = false
	): void {
		parent::addPage( $page, $sortkey, $pageLength, $isRedirect );

		$title = MediaWikiServices::getInstance()->getTitleFactory()->newFromPageReference( $page );
		$letter = $this->start_key( $sortkey );
		$this->page_members[] = [
			'title' => $title,
			'letter' => $letter,
			'is_redirect' => $isRedirect,
			'sortkey' => $sortkey,
		];
	}

	/** @inheritDoc */
	protected function finaliseCategoryState() {
		parent::finaliseCategoryState();
		if ( $this->flip['page'] ) {
			$this->page_members = array_reverse( $this->page_members );
		}
	}

	/** @inheritDoc */
	public function getHTML() {
		$html = parent::getHTML();

		if ( $this->page_members === [] ) {
			return $html;
		}

		$html = $this->splice_pages_app( $html );
		$html = $this->strip_core_page_paging( $html );
		$html = $this->strip_pages_count_message( $html );

		$this->publish_config();
		$this->getOutput()->addModuleStyles( [ AzIndex::STYLE_MODULE ] );
		$this->getOutput()->addModules( [ AzIndex::APP_MODULE ] );

		return $html;
	}

	private function publish_config(): void {
		$width = max( 1, (int)$this->getConfig()->get( 'AdvancedCategoriesThumbnailWidth' ) );
		$height = max( 1, (int)round( $width * 9 / 16 ) );
		$titles = [];
		foreach ( $this->page_members as $member ) {
			$titles[] = $member['title'];
		}
		$thumbnails = $this->page_images()->thumbnails_for_titles( $titles, $width, $height );

		$pages = [];
		foreach ( $this->page_members as $member ) {
			$title = $member['title'];
			$page_id = $title->getArticleID();
			$pages[] = [
				'page_id' => $page_id,
				'title' => $title->getPrefixedText(),
				'display_title' => $title->getText(),
				'url' => $title->getLocalURL(),
				'letter' => $member['letter'],
				'is_redirect' => $member['is_redirect'],
				'thumbnail' => $thumbnails[$page_id] ?? null,
			];
		}

		$this->getOutput()->addJsConfigVars( [
			'wgAdvancedCategories' => [
				'letters' => $this->letter_config(),
				'pages' => $pages,
				'pagination' => $this->pagination_config(),
			],
		] );
	}

	/**
	 * @return list<array{letter: string, url: string, page: int, current: bool}>
	 */
	private function letter_config(): array {
		$paging = $this->pagination_config();
		$az_index = $this->az_index();
		$index = $this->paging_index()->page_index( $this->page, $paging['limit'], $paging['total'] );
		$letter_starts = $index === null ? [] : $index['letter_starts'];

		$buckets = [];
		foreach ( $letter_starts as $letter => $info ) {
			$bucket = $az_index->bucket( (string)$letter );
			$page = (int)$info['page'];
			$from = (string)$info['from'];
			if ( !isset( $buckets[$bucket] ) || $page < $buckets[$bucket]['page'] ) {
				$buckets[$bucket] = [
					'page' => $page,
					'from' => $from,
				];
			}
		}

		foreach ( $this->page_members as $member ) {
			$letter = $member['letter'];
			if ( $letter === '' ) {
				continue;
			}
			$bucket = $az_index->bucket( $letter );
			if ( isset( $buckets[$bucket] ) ) {
				continue;
			}
			$buckets[$bucket] = [
				'page' => $paging['page'],
				'from' => $member['sortkey'],
			];
		}

		$active_bucket = $this->active_bucket( $buckets );

		$letters = [];
		foreach ( $buckets as $bucket => $info ) {
			$letters[] = [
				'letter' => $bucket,
				'url' => $this->letter_url( $bucket, $info['from'] ),
				'page' => $info['page'],
				'current' => $bucket === $active_bucket,
			];
		}

		return $letters;
	}

	/**
	 * @param array<string, array{page: int, from: string}> $buckets
	 */
	private function active_bucket( array $buckets ): ?string {
		$from = $this->from['page'] ?? null;
		if (
			( $from === null || $from === '' ) &&
			!isset( $this->until['page'] ) &&
			isset( $buckets[ AzIndex::HASH_BUCKET ] )
		) {
			return AzIndex::HASH_BUCKET;
		}

		foreach ( $this->page_members as $member ) {
			if ( $member['letter'] !== '' ) {
				return $this->az_index()->bucket( $member['letter'] );
			}
		}

		return null;
	}

	private function letter_url( string $bucket, string $from ): string {
		if ( $bucket === AzIndex::HASH_BUCKET || $from === '' ) {
			return $this->category_url( null, null, '' );
		}

		return $this->category_url( $from, null, '' );
	}

	/**
	 * @return array{
	 *   total: int,
	 *   limit: int,
	 *   start: int,
	 *   end: int,
	 *   page: int,
	 *   page_count: int,
	 *   precise: bool,
	 *   first_url: ?string,
	 *   prev_url: ?string,
	 *   next_url: ?string,
	 *   last_url: ?string,
	 *   page_links: list<array{page: int, url: ?string}>
	 * }
	 */
	private function pagination_config(): array {
		if ( $this->pagination !== null ) {
			return $this->pagination;
		}

		$limit = max( 1, $this->limit );
		$shown = count( $this->page_members );
		$has_prev = isset( $this->from['page'] ) || isset( $this->until['page'] );
		$next_from = $this->nextPage['page'] ?? null;
		$has_next = $next_from !== null && $next_from !== '';
		$total = max( $shown, $this->paging_index()->page_member_count( $this->page ) );
		$starts = $this->paging_index()->starts( $this->page, $limit, $total );
		$page_count = max( 1, (int)ceil( $total / $limit ) );
		[ $page_num, $precise ] = $this->current_page_number( $starts, $page_count );
		if ( !$has_prev ) {
			$page_num = 1;
			$precise = true;
		}
		$start = $shown > 0 ? ( ( $page_num - 1 ) * $limit ) + 1 : 0;
		$end = $shown > 0 ? min( $total, $start + $shown - 1 ) : 0;

		$first_url = $has_prev ? $this->category_url( null ) : null;
		$prev_url = null;
		if ( $has_prev ) {
			if ( $starts !== null && $page_num > 1 ) {
				$prev_from = $starts[ $page_num - 2 ] ?? '';
				$prev_url = $this->category_url( $prev_from === '' ? null : $prev_from );
			} else {
				$prev_url = $this->legacy_prev_url();
			}
		}
		$next_url = $has_next ? $this->category_url( $next_from ) : null;
		$last_url = null;
		if ( $has_next ) {
			$last_from = $starts !== null
				? ( $starts[ $page_count - 1 ] ?? null )
				: $this->paging_index()->last_start( $this->page, $limit, $total );
			if ( is_string( $last_from ) && $last_from !== '' ) {
				$last_url = $this->category_url( $last_from );
			}
		}

		$this->pagination = [
			'total' => $total,
			'limit' => $limit,
			'start' => $shown > 0 ? $start : 0,
			'end' => $shown > 0 ? $end : 0,
			'page' => $page_num,
			'page_count' => $page_count,
			'precise' => $precise,
			'first_url' => $first_url,
			'prev_url' => $prev_url,
			'next_url' => $next_url,
			'last_url' => $last_url,
			'page_links' => $this->page_links( $starts, $page_count, $page_num, $limit, $total ),
		];

		return $this->pagination;
	}

	/**
	 * @param string[]|null $starts
	 * @return array{0: int, 1: bool}
	 */
	private function current_page_number( ?array $starts, int $page_count ): array {
		$from = $this->from['page'] ?? null;
		if ( ( $from === null || $from === '' ) && !isset( $this->until['page'] ) ) {
			return [ 1, true ];
		}

		$needle = $from;
		if ( ( $needle === null || $needle === '' ) && $this->page_members !== [] ) {
			$needle = $this->page_members[0]['sortkey'];
		}
		if ( $needle === null || $needle === '' || $starts === null ) {
			return [ 1, false ];
		}

		$found = array_search( $needle, $starts, true );
		if ( $found !== false ) {
			return [ min( $page_count, (int)$found + 1 ), true ];
		}

		return [ $this->containing_page( $starts, $needle, $page_count ), true ];
	}

	/**
	 * @param string[] $starts
	 */
	private function containing_page( array $starts, string $needle, int $page_count ): int {
		$page = 1;
		foreach ( $starts as $i => $start ) {
			if ( $start === '' ) {
				$page = 1;
				continue;
			}
			if ( $this->sortkey_leq( $start, $needle ) ) {
				$page = $i + 1;
				continue;
			}
			break;
		}

		return min( $page_count, max( 1, $page ) );
	}

	private function sortkey_leq( string $left, string $right ): bool {
		return strcmp(
			$this->collation->getSortKey( $left ),
			$this->collation->getSortKey( $right )
		) <= 0;
	}

	private function legacy_prev_url(): ?string {
		$from = $this->from['page'] ?? '';
		if ( $from !== '' ) {
			return $this->category_url( null, $from );
		}
		$until = $this->until['page'] ?? '';
		if ( $until !== '' && ( $this->prevPage['page'] ?? '' ) !== '' ) {
			return $this->category_url( null, $this->prevPage['page'] );
		}

		return $this->category_url( null );
	}

	/**
	 * @param string[]|null $starts
	 * @return list<array{page: int, url: ?string}>
	 */
	private function page_links(
		?array $starts,
		int $page_count,
		int $page_num,
		int $limit,
		int $total
	): array {
		if ( $starts !== null ) {
			$links = [];
			for ( $page = 1; $page <= $page_count; $page++ ) {
				$from = $starts[ $page - 1 ] ?? null;
				if ( $from === null && $page !== 1 ) {
					continue;
				}
				$url = null;
				if ( $page !== $page_num ) {
					$url = $this->category_url( $from === '' || $from === null ? null : $from );
				}
				$links[] = [
					'page' => $page,
					'url' => $url,
				];
			}

			return $links;
		}

		$links = [
			[
				'page' => 1,
				'url' => $page_num === 1 ? null : $this->category_url( null ),
			],
		];
		if ( $page_num !== 1 && $page_num !== $page_count ) {
			$links[] = [
				'page' => $page_num,
				'url' => null,
			];
		}
		if ( $page_count > 1 ) {
			$last_from = $this->paging_index()->last_start( $this->page, $limit, $total );
			$last_url = null;
			if ( $page_num !== $page_count && is_string( $last_from ) && $last_from !== '' ) {
				$last_url = $this->category_url( $last_from );
			}
			$links[] = [
				'page' => $page_count,
				'url' => $last_url,
			];
		}

		return $links;
	}

	private function category_url(
		?string $pagefrom,
		?string $pageuntil = null,
		string $fragment = 'mw-pages'
	): string {
		$query = $this->getRequest()->getQueryValues();
		unset( $query['title'], $query['pagefrom'], $query['pageuntil'] );
		if ( $pagefrom !== null && $pagefrom !== '' ) {
			$query['pagefrom'] = $pagefrom;
		}
		if ( $pageuntil !== null && $pageuntil !== '' ) {
			$query['pageuntil'] = $pageuntil;
		}

		$title = Title::newFromPageIdentity( $this->page );
		if ( $fragment !== '' ) {
			$title = $title->createFragmentTarget( $fragment );
		}

		return $title->getLinkURL( $query );
	}

	private function splice_pages_app( string $html ): string {
		if ( !preg_match( '/<div id="mw-pages">/', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$pages_start = (int)$match[0][1];
		$pages_end = $this->matching_div_end( $html, $pages_start );
		$pages_html = substr( $html, $pages_start, $pages_end - $pages_start );
		$replaced = $this->replace_category_list( $pages_html, $this->pages_app_html() );

		return substr( $html, 0, $pages_start ) . $replaced . substr( $html, $pages_end );
	}

	private function strip_core_page_paging( string $html ): string {
		$stripped = preg_replace(
			'/\s*\(\s*<a\b[^>]*(?:pagefrom|pageuntil)=[^>]*>.*?<\/a>\s*\)/is',
			'',
			$html
		);
		$html = is_string( $stripped ) ? $stripped : $html;

		$prev = preg_quote( $this->msg( 'prev-page' )->numParams( $this->limit )->text(), '/' );
		$next = preg_quote( $this->msg( 'next-page' )->numParams( $this->limit )->text(), '/' );
		if ( $prev !== '' || $next !== '' ) {
			$plain = preg_replace(
				'/\s*\(\s*(?:' . $prev . '|' . $next . ')\s*\)/u',
				'',
				$html
			);
			$html = is_string( $plain ) ? $plain : $html;
		}

		return $html;
	}

	private function strip_pages_count_message( string $html ): string {
		if ( !preg_match( '/<div id="mw-pages">/', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$pages_start = (int)$match[0][1];
		$pages_end = $this->matching_div_end( $html, $pages_start );
		$pages_html = substr( $html, $pages_start, $pages_end - $pages_start );
		$stripped = preg_replace(
			'/(<h2\b[^>]*>.*?<\/h2>\s*)<p\b[^>]*>.*?<\/p>\s*/s',
			'$1',
			$pages_html,
			1
		);
		if ( !is_string( $stripped ) ) {
			return $html;
		}

		return substr( $html, 0, $pages_start ) . $stripped . substr( $html, $pages_end );
	}

	private function replace_category_list( string $pages_html, string $replacement ): string {
		if ( !preg_match(
			'/<div class="mw-category(?: mw-category-columns)?"/',
			$pages_html,
			$match,
			PREG_OFFSET_CAPTURE
		) ) {
			$end = $this->matching_div_end( $pages_html, 0 );

			return substr( $pages_html, 0, $end - 6 ) . $replacement . substr( $pages_html, $end - 6 );
		}

		$list_start = (int)$match[0][1];
		$list_end = $this->matching_div_end( $pages_html, $list_start );

		return substr( $pages_html, 0, $list_start ) . $replacement . substr( $pages_html, $list_end );
	}

	private function pages_app_html(): string {
		$items = [];
		foreach ( $this->page_members as $member ) {
			$title = $member['title'];
			$link = Html::element(
				'a',
				[ 'href' => $title->getLocalURL() ],
				$title->getPrefixedText()
			);
			$items[] = Html::rawElement( 'li', [], $link );
		}

		$paging = $this->pagination_config();
		$nav_items = [];
		if ( $paging['prev_url'] ) {
			$nav_items[] = Html::element(
				'a',
				[ 'href' => $paging['prev_url'] ],
				$this->msg( 'table_pager_prev' )->text()
			);
		}
		if ( $paging['next_url'] ) {
			$nav_items[] = Html::element(
				'a',
				[ 'href' => $paging['next_url'] ],
				$this->msg( 'table_pager_next' )->text()
			);
		}

		$noscript_inner = '';
		if ( $nav_items !== [] ) {
			$noscript_inner .= Html::rawElement( 'p', [], implode( ' ', $nav_items ) );
		}
		$noscript_inner .= Html::rawElement( 'ul', [], implode( '', $items ) );

		$noscript = Html::rawElement( 'noscript', [], $noscript_inner );

		return Html::rawElement( 'div', [ 'id' => 'advancedcategories-pages-app' ], $noscript );
	}

	private function matching_div_end( string $html, int $open_pos ): int {
		$length = strlen( $html );
		$pos = $open_pos;
		$depth = 0;

		while ( $pos < $length ) {
			$next_open = strpos( $html, '<div', $pos );
			$next_close = strpos( $html, '</div>', $pos );
			if ( $next_close === false ) {
				return $length;
			}

			if ( $next_open !== false && $next_open < $next_close ) {
				$after = substr( $html, $next_open + 4, 1 );
				if ( $after === '' || ctype_space( $after ) || $after === '>' ) {
					$depth++;
				}
				$pos = $next_open + 4;
				continue;
			}

			$depth--;
			$pos = $next_close + 6;
			if ( $depth === 0 ) {
				return $pos;
			}
		}

		return $length;
	}

	private function start_key( string $sortkey ): string {
		if ( isset( $this->sortByTimestamp ) && $this->sortByTimestamp ) {
			return $this->az_index()->normalize_letter( $sortkey );
		}

		return $this->az_index()->letter_from_sortkey(
			$sortkey,
			$this->collation,
			MediaWikiServices::getInstance()->getLanguageConverterFactory()->getLanguageConverter()
		);
	}

	private function az_index(): AzIndex {
		return MediaWikiServices::getInstance()->get( 'AdvancedCategories.AzIndex' );
	}

	private function page_images(): PageImagesLookup {
		return MediaWikiServices::getInstance()->get( 'AdvancedCategories.PageImagesLookup' );
	}

	private function paging_index(): CategoryPagingIndex {
		return MediaWikiServices::getInstance()->get( 'AdvancedCategories.CategoryPagingIndex' );
	}

}
