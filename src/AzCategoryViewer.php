<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\Category\Category;
use MediaWiki\Category\CategoryViewer;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\Title;

class AzCategoryViewer extends CategoryViewer {

	/** @var array<int, array{title: Title, letter: string, is_redirect: bool, sortkey: string}> */
	private array $page_members = [];

	/** @var array<int, array{title: Title, is_redirect: bool, sortkey: string}> */
	private array $subcategory_members = [];

	/** @var array{total: int, limit: int, start: int, end: int, page: int, page_count: int, precise: bool, first_url: ?string, prev_url: ?string, next_url: ?string, last_url: ?string, page_links: list<array{page: int, url: ?string}>}|null */
	private ?array $pagination = null;

	/** @inheritDoc */
	protected function clearCategoryState() {
		$this->page_members = [];
		$this->subcategory_members = [];
		$this->pagination = null;
		parent::clearCategoryState();
	}

	/** @inheritDoc */
	public function addSubcategoryObject( Category $cat, string $sortkey, int $pageLength ): void {
		$page = $cat->getPage();
		parent::addSubcategoryObject( $cat, $sortkey, $pageLength );
		if ( $page === null ) {
			return;
		}

		$title = MediaWikiServices::getInstance()->getTitleFactory()->newFromPageReference( $page );
		$this->subcategory_members[] = [
			'title' => $title,
			'is_redirect' => $title->isRedirect(),
			'sortkey' => $sortkey,
		];
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
		if ( $this->flip['subcat'] ) {
			$this->subcategory_members = array_reverse( $this->subcategory_members );
		}
	}

	/** @inheritDoc */
	protected function getSubcategorySection() {
		$html = parent::getSubcategorySection();
		if ( $html === '' || $this->subcategory_members === [] ) {
			return $html;
		}

		$this->getOutput()->addModuleStyles( [ AzIndex::STYLE_MODULE ] );

		return $this->replace_category_list( $html, $this->subcategories_html() );
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

		$this->getOutput()->addModuleStyles( [ AzIndex::STYLE_MODULE ] );
		$this->getOutput()->addModules( [ AzIndex::APP_MODULE ] );

		return $html;
	}

	/**
	 * @return array{pages: list<array{page_id: int, title: string, display_title: string, url: string, letter: string, is_redirect: bool, thumbnail: ?array{url: string, width: int, height: int}, description: ?string, cargo: array<string, mixed>}>, columns: list<array{key: string, label: string}>}
	 */
	private function member_list_data(): array {
		$width = max( 1, (int)$this->getConfig()->get( 'AdvancedCategoriesThumbnailWidth' ) );
		$height = max( 1, (int)round( $width * 9 / 16 ) );
		$titles = [];
		foreach ( $this->page_members as $member ) {
			$titles[] = $member['title'];
		}
		$thumbnails = $this->page_images()->thumbnails_for_titles( $titles, $width, $height );
		$descriptions = $this->page_descriptions()->descriptions_for_titles( $titles );
		$declaration = $this->cargo_lookup()->declaration_for_title(
			Title::newFromPageIdentity( $this->page )
		);
		$columns = $declaration['fields'] ?? [];
		$cargo_rows = [];
		if ( $declaration !== null ) {
			$page_ids = [];
			foreach ( $titles as $title ) {
				$page_ids[] = $title->getArticleID();
			}
			$field_keys = [];
			foreach ( $columns as $column ) {
				$field_keys[] = $column['key'];
			}
			$cargo_rows = $this->cargo_lookup()->values_for_page_ids(
				$declaration['table'],
				$field_keys,
				$page_ids
			);
		}

		$pages = [];
		foreach ( $this->page_members as $member ) {
			$title = $member['title'];
			$page_id = $title->getArticleID();
			$cargo = [];
			foreach ( $columns as $column ) {
				$cargo[$column['key']] = $cargo_rows[$page_id][$column['key']] ?? null;
			}
			$pages[] = [
				'page_id' => $page_id,
				'title' => $title->getPrefixedText(),
				'display_title' => $title->getText(),
				'url' => $title->getLocalURL(),
				'letter' => $member['letter'],
				'is_redirect' => $member['is_redirect'],
				'thumbnail' => $thumbnails[$page_id] ?? null,
				'description' => $descriptions[$page_id] ?? null,
				'cargo' => $cargo,
			];
		}

		return [
			'pages' => $pages,
			'columns' => $columns,
		];
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
				'url' => $this->letter_url( $bucket, $info['from'], $info['page'] ),
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

	private function letter_url( string $bucket, string $from, int $page ): string {
		$fragment = AzIndex::letter_id( $bucket );
		$paging = $this->pagination_config();
		if ( $page === $paging['page'] ) {
			$current_from = $this->from['page'] ?? null;
			$current_until = $this->until['page'] ?? null;
			$base = $this->category_url(
				is_string( $current_from ) && $current_from !== '' ? $current_from : null,
				is_string( $current_until ) && $current_until !== '' ? $current_until : null,
				''
			);
			return $base . '#' . $fragment;
		}

		if ( $bucket === AzIndex::HASH_BUCKET || $from === '' ) {
			return $this->category_url( null, null, '' ) . '#' . $fragment;
		}

		return $this->category_url( $from, null, '' ) . '#' . $fragment;
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

	private function subcategories_html(): string {
		$titles = [];
		foreach ( $this->subcategory_members as $member ) {
			$titles[] = $member['title'];
		}
		$descriptions = $this->page_descriptions()->descriptions_for_titles( $titles );

		$items = [];
		foreach ( $this->subcategory_members as $member ) {
			$title = $member['title'];
			$item = [
				'url' => $title->getLocalURL(),
				'title' => $title->getText(),
				'is_redirect' => $member['is_redirect'],
			];
			$description = $descriptions[ $title->getArticleID() ] ?? null;
			if ( is_string( $description ) && $description !== '' ) {
				$item['description'] = $description;
			}
			$items[] = $item;
		}

		return $this->template_parser()->processTemplate( 'Subcategories', [
			'items' => $items,
		] );
	}

	private function template_parser(): TemplateParser {
		return new TemplateParser( dirname( __DIR__ ) . '/templates' );
	}

	private function pages_app_html(): string {
		$data = $this->member_list_data();

		return $this->template_parser()->processTemplate( 'Pages', [
			'az' => $this->az_view(),
			'pager' => $this->pager_view(),
			'grid' => $this->grid_view( $data['pages'], $data['columns'] ),
		] );
	}

	/**
	 * @return array{aria_label: string, items: list<array<string, mixed>>}
	 */
	private function az_view(): array {
		$az_index = $this->az_index();
		$other_label = $this->msg( 'advancedcategories-az-index-other' )->text();
		$items = [];
		foreach ( $az_index->index_letters( $this->letter_config() ) as $item ) {
			$label = $item['letter'] === AzIndex::OTHER_BUCKET ? $other_label : $item['label'];
			$page = $item['page'];
			$class = 'advancedcategories-az-index__letter';
			if ( $item['letter'] === AzIndex::OTHER_BUCKET ) {
				$class .= ' advancedcategories-az-index__letter--other';
			}
			if ( $item['href'] === null ) {
				$class .= ' advancedcategories-az-index__letter--empty';
			}
			if ( $item['current'] ) {
				$class .= ' advancedcategories-az-index__letter--current';
			}
			if ( $page === null ) {
				$title = $this->msg( 'advancedcategories-az-index-letter', $label )->text();
			} else {
				$title = $this->msg(
					'advancedcategories-az-index-letter-page',
					$label,
					$this->format_num( $page )
				)->text();
			}
			$items[] = [
				'has_href' => $item['href'] !== null && $item['href'] !== '',
				'href' => $item['href'] ?? '',
				'class' => $class,
				'title' => $title,
				'label' => $label,
				'has_page' => $page !== null,
				'page' => $page === null ? '' : $this->format_num( $page ),
				'current' => $item['current'],
			];
		}

		return [
			'aria_label' => $this->msg( 'advancedcategories-az-index-aria' )->text(),
			'items' => $items,
		];
	}

	/**
	 * @return array<string, mixed>|false
	 */
	private function pager_view(): array|false {
		$paging = $this->pagination_config();
		if ( $paging['page_count'] <= 1 && !$paging['prev_url'] && !$paging['next_url'] ) {
			return false;
		}

		$href_by_page = [];
		$known_pages = [];
		foreach ( $paging['page_links'] as $link ) {
			$href_by_page[ $link['page'] ] = $link['url'];
			$known_pages[ $link['page'] ] = true;
		}

		$jump_label = $this->msg(
			'advancedcategories-pager-jump',
			$this->format_num( $paging['page_count'] )
		)->text();
		$hrefs = [];
		for ( $page = 1; $page <= $paging['page_count']; $page++ ) {
			$href = $this->page_href( $paging, $page, $href_by_page );
			if ( $href ) {
				$hrefs[ (string)$page ] = $href;
			}
		}

		$slots = [];
		foreach ( Pager::visible_slots( $paging['page'], $paging['page_count'], $known_pages ) as $slot ) {
			if ( $slot['kind'] === 'ellipsis' ) {
				$slots[] = [
					'is_page' => false,
					'jump' => [
						'label' => $jump_label,
						'size' => (string)max( 2, strlen( (string)$paging['page_count'] ) ),
					],
				];
				continue;
			}

			$href = $this->page_href( $paging, $slot['page'], $href_by_page );
			$disabled = $href === null && !$slot['current'];
			$class = 'advancedcategories-pager__button advancedcategories-pager__page';
			if ( $slot['current'] ) {
				$class .= ' advancedcategories-pager__page--current';
			} elseif ( $disabled ) {
				$class .= ' advancedcategories-pager__page--disabled';
			}
			$slots[] = [
				'is_page' => true,
				'has_href' => $href !== null && $href !== '',
				'href' => $href ?? '',
				'class' => $class,
				'current' => $slot['current'],
				'disabled' => $disabled,
				'label' => $this->msg(
					'advancedcategories-pager-page',
					$this->format_num( $slot['page'] )
				)->text(),
				'text' => $this->format_num( $slot['page'] ),
			];
		}

		$prev_label = $this->msg( 'table_pager_prev' )->text();
		$next_label = $this->msg( 'table_pager_next' )->text();
		$prev_class = 'advancedcategories-pager__button advancedcategories-pager__icon';
		$next_class = $prev_class;
		if ( !$paging['prev_url'] ) {
			$prev_class .= ' advancedcategories-pager__icon--disabled';
		}
		if ( !$paging['next_url'] ) {
			$next_class .= ' advancedcategories-pager__icon--disabled';
		}

		if ( $paging['precise'] && $paging['start'] > 0 ) {
			$status = $this->msg(
				'advancedcategories-pager-status',
				$this->format_num( $paging['start'] ),
				$this->format_num( $paging['end'] ),
				$this->format_num( $paging['total'] )
			)->text();
		} else {
			$status = $this->msg(
				'advancedcategories-pager-status-total',
				$this->format_num( $paging['total'] )
			)->text();
		}

		return [
			'aria_label' => $this->msg( 'advancedcategories-pager-aria' )->text(),
			'status' => $status,
			'current_page' => (string)$paging['page'],
			'page_count' => (string)$paging['page_count'],
			'hrefs_json' => ( FormatJson::encode( $hrefs ) ?: '{}' ),
			'prev_href' => $paging['prev_url'] ?? '',
			'has_prev' => (bool)$paging['prev_url'],
			'prev_class' => $prev_class,
			'prev_label' => $prev_label,
			'prev_glyph' => $this->pager_icon( '<path d="M13.417 4.707 8.124 10l5.293 5.293-1.414 1.414-6-6V9.293l6-6z"/>' ),
			'next_href' => $paging['next_url'] ?? '',
			'has_next' => (bool)$paging['next_url'],
			'next_class' => $next_class,
			'next_label' => $next_label,
			'next_glyph' => $this->pager_icon( '<path d="M14 9.293v1.414l-5.982 6-1.415-1.414L11.896 10 6.603 4.707l1.414-1.414z"/>' ),
			'slots' => $slots,
		];
	}

	/**
	 * @param array{total: int, limit: int, start: int, end: int, page: int, page_count: int, precise: bool, first_url: ?string, prev_url: ?string, next_url: ?string, last_url: ?string, page_links: list<array{page: int, url: ?string}>} $paging
	 * @param int $page
	 * @param array<int, ?string> $href_by_page
	 */
	private function page_href( array $paging, int $page, array $href_by_page ): ?string {
		if ( $page === $paging['page'] ) {
			return null;
		}
		if ( array_key_exists( $page, $href_by_page ) ) {
			return $href_by_page[$page];
		}
		if ( $page === 1 ) {
			return $paging['first_url'];
		}
		if ( $page === $paging['page_count'] ) {
			return $paging['last_url'];
		}

		return null;
	}

	private function pager_icon( string $path_html ): string {
		$classes = 'cdx-icon';
		if ( $this->getLanguage()->isRTL() ) {
			$classes .= ' cdx-icon--flipped';
		}

		return Html::rawElement(
			'span',
			[
				'class' => 'advancedcategories-pager__glyph',
				'aria-hidden' => 'true',
			],
			Html::rawElement(
				'span',
				[ 'class' => $classes ],
				'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
				. $path_html
				. '</svg>'
			)
		);
	}

	/**
	 * @param list<array{page_id: int, title: string, display_title: string, url: string, letter: string, is_redirect: bool, thumbnail: ?array{url: string, width: int, height: int}, description: ?string, cargo: array<string, mixed>}> $pages
	 * @param list<array{key: string, label: string}> $columns
	 * @return array{caption: string, has_cargo: bool, page_heading: string, columns: list<array{label: string}>, column_count: int, groups: list<array<string, mixed>>}
	 */
	private function grid_view( array $pages, array $columns ): array {
		$az_index = $this->az_index();
		$grouped = [];
		foreach ( $pages as $page ) {
			$grouped[ $page['letter'] ][] = $page;
		}

		$seen = [];
		$groups = [];
		foreach ( $grouped as $letter => $group_pages ) {
			$bucket = $az_index->bucket( (string)$letter );
			$id = isset( $seen[$bucket] ) ? false : AzIndex::letter_id( $bucket );
			if ( $id ) {
				$seen[$bucket] = true;
			}

			$rows = [];
			foreach ( $group_pages as $page ) {
				$thumb = $page['thumbnail'];
				$description = $page['description'];
				$cargo_cells = [];
				foreach ( $columns as $column ) {
					$cargo_cells[] = [
						'text' => $this->cargo_text( $page['cargo'][ $column['key'] ] ?? null ),
					];
				}
				$row = [
					'url' => $page['url'],
					'display_title' => $page['display_title'],
					'is_redirect' => $page['is_redirect'],
					'thumb_style' => $this->thumb_style( $thumb ),
					'has_thumbnail' => $thumb !== null,
					'thumb_url' => $thumb['url'] ?? '',
					'thumb_width' => $thumb['width'] ?? 0,
					'thumb_height' => $thumb['height'] ?? 0,
					'cargo_cells' => $cargo_cells,
				];
				if ( is_string( $description ) && $description !== '' ) {
					$row['description'] = $description;
				}
				$rows[] = $row;
			}

			$groups[] = [
				'has_group_id' => is_string( $id ) && $id !== '',
				'group_id' => is_string( $id ) ? $id : '',
				'label' => $letter === ' ' ? "\u{00A0}" : (string)$letter,
				'column_count' => 1 + count( $columns ),
				'pages' => $rows,
			];
		}

		$column_labels = [];
		foreach ( $columns as $column ) {
			$column_labels[] = [
				'label' => $column['label'],
			];
		}

		return [
			'caption' => $this->msg( 'advancedcategories-agrid-caption' )->text(),
			'has_cargo' => $columns !== [],
			'page_heading' => $this->msg( 'advancedcategories-column-page' )->text(),
			'columns' => $column_labels,
			'column_count' => 1 + count( $columns ),
			'groups' => $groups,
		];
	}

	private function thumb_style( ?array $thumb ): string {
		if ( $thumb === null || $thumb['width'] < 1 || $thumb['height'] < 1 ) {
			return 'aspect-ratio: 16 / 9';
		}

		$ratio = min( 21 / 9, max( 1 / 2, $thumb['width'] / $thumb['height'] ) );

		return 'aspect-ratio: ' . $ratio;
	}

	private function cargo_text( mixed $value ): string {
		if ( $value === null ) {
			return '';
		}
		if ( is_array( $value ) ) {
			$parts = [];
			foreach ( $value as $part ) {
				if ( $part !== '' ) {
					$parts[] = (string)$part;
				}
			}

			return implode( ', ', $parts );
		}

		return (string)$value;
	}

	private function format_num( int $value ): string {
		return $this->getLanguage()->formatNum( $value );
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

	private function page_descriptions(): PageDescriptionsLookup {
		return MediaWikiServices::getInstance()->get( 'AdvancedCategories.PageDescriptionsLookup' );
	}

	private function cargo_lookup(): CargoLookup {
		return MediaWikiServices::getInstance()->get( 'AdvancedCategories.CargoLookup' );
	}

	private function paging_index(): CategoryPagingIndex {
		return MediaWikiServices::getInstance()->get( 'AdvancedCategories.CategoryPagingIndex' );
	}

}
