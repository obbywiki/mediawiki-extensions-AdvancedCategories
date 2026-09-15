<?php

namespace MediaWiki\Extension\AdvancedCategories;

use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Page\PageProps;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

class PageImagesLookup {

	private const PROP_FREE = 'page_image_free';
	private const PROP_ANY = 'page_image';

	public function __construct(
		private readonly PageProps $page_props,
		private readonly RepoGroup $repo_group,
	) {
	}

	/**
	 * @param Title[] $titles
	 * @return array<int, array{url: string, width: int, height: int}>
	 */
	public function thumbnails_for_titles( array $titles, int $width, int $height = 0 ): array {
		if ( $titles === [] || $width < 1 ) {
			return [];
		}
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'PageImages' ) ) {
			return [];
		}

		$file_names = [];
		foreach ( array_chunk( $titles, 500 ) as $chunk ) {
			$props = $this->page_props->getProperties( $chunk, [ self::PROP_FREE, self::PROP_ANY ] );
			foreach ( $props as $page_id => $values ) {
				$file_name = $values[self::PROP_FREE] ?? $values[self::PROP_ANY] ?? null;
				if ( is_string( $file_name ) && $file_name !== '' ) {
					$file_names[(int)$page_id] = $file_name;
				}
			}
		}

		if ( $file_names === [] ) {
			return [];
		}

		$found = $this->repo_group->findFiles( array_unique( array_values( $file_names ) ) );
		$files = [];
		foreach ( $found as $key => $file ) {
			$dbkey = str_replace( ' ', '_', (string)$key );
			$files[$dbkey] = $file;
			$files[str_replace( '_', ' ', $dbkey )] = $file;
		}

		$thumbnails = [];
		foreach ( $file_names as $page_id => $file_name ) {
			$dbkey = str_replace( ' ', '_', $file_name );
			$file = $files[$dbkey] ?? $files[$file_name] ?? null;
			if ( !$file instanceof File ) {
				continue;
			}

			$params = [ 'width' => $width ];
			if ( $height > 0 ) {
				$params['height'] = $height;
			}
			$thumb = $file->transform( $params );
			if ( !$thumb || $thumb->isError() ) {
				continue;
			}

			$url = $thumb->getUrl();
			if ( !is_string( $url ) || $url === '' ) {
				continue;
			}

			$thumbnails[$page_id] = [ 'url' => $url, 'width' => (int)$thumb->getWidth(), 'height' => (int)$thumb->getHeight() ];
		}

		return $thumbnails;
	}

}
