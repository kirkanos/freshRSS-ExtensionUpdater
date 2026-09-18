<?php

declare(strict_types=1);

/**
 * Works out where an extension's source repository lives.
 *
 * An extension's own metadata.json is the best answer but is frequently
 * missing the "url" field. The indexes carry that URL for every extension
 * they list, so they serve as the fallback — without it, a stale index entry
 * is the only version signal we ever get for such an extension.
 */
final class EURepoUrls
{
	/** @var list<EUIndexSource> */
	private array $indexes = [];

	public function addIndex(EUIndexSource $index): void
	{
		$this->indexes[] = $index;
	}

	public function resolve(EUExtension $ext): string
	{
		if ($ext->url !== '') {
			return $ext->url;
		}
		foreach ($this->indexes as $index) {
			$url = $index->repoUrlFor($ext);
			if ($url !== '') {
				return $url;
			}
		}
		return '';
	}
}
