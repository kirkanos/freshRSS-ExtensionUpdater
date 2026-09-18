<?php

declare(strict_types=1);

/** What a source found out about one extension. */
final class EUUpdateInfo
{
	public string $sourceId;
	public string $sourceLabel = '';
	public string $remoteVersion = '';
	public string $downloadUrl = '';
	/** Alternative archive URLs, tried in order when the first one 404s. */
	public array $downloadFallbacks = [];
	public string $releaseUrl = '';
	public string $note = '';
	public bool $updateAvailable = false;

	public function __construct(string $sourceId, string $sourceLabel = '')
	{
		$this->sourceId = $sourceId;
		$this->sourceLabel = $sourceLabel !== '' ? $sourceLabel : $sourceId;
	}

	/** @return list<string> */
	public function downloadCandidates(): array
	{
		$urls = $this->downloadUrl !== '' ? [$this->downloadUrl] : [];
		foreach ($this->downloadFallbacks as $url) {
			if (is_string($url) && $url !== '' && !in_array($url, $urls, true)) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	public function canInstall(): bool
	{
		return $this->updateAvailable && $this->downloadUrl !== '';
	}
}
