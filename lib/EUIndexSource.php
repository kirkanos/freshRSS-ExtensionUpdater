<?php

declare(strict_types=1);

/**
 * An extensions.json index — the official FreshRSS one, or any third-party list
 * in the same format. Fetched once and reused for every extension.
 */
final class EUIndexSource implements EUSource
{
	public const OFFICIAL_URL = 'https://raw.githubusercontent.com/FreshRSS/Extensions/refs/heads/main/extensions.json';

	private string $url;
	private string $label;
	private EUGitHub $github;
	/** @var array<string,array<string,mixed>>|null */
	private ?array $index = null;
	private ?string $error = null;

	public function __construct(string $url, string $label, EUGitHub $github)
	{
		$this->url = $url;
		$this->label = $label;
		$this->github = $github;
	}

	public function id(): string
	{
		return 'index:' . $this->url;
	}

	public function label(): string
	{
		return $this->label;
	}

	public function error(): ?string
	{
		return $this->error;
	}

	public function check(EUExtension $ext): ?EUUpdateInfo
	{
		$index = $this->index();
		$entry = $index[$ext->key()] ?? null;
		if ($entry === null) {
			return null;
		}

		$remoteVersion = EUVersion::toString($entry['version'] ?? null);
		if ($remoteVersion === '') {
			return null;
		}

		$info = new EUUpdateInfo($this->id(), $this->label());
		$info->remoteVersion = $remoteVersion;
		$info->updateAvailable = EUVersion::isNewer($remoteVersion, $ext->version);
		$info->releaseUrl = is_string($entry['url'] ?? null) ? $entry['url'] : '';

		if ($info->updateAvailable) {
			$candidates = $this->resolveDownloadUrls($entry);
			$info->downloadUrl = $candidates[0] ?? '';
			$info->downloadFallbacks = array_slice($candidates, 1);
			if ($info->downloadUrl === '') {
				$info->note = 'no-download-url';
			}
		}
		return $info;
	}

	/**
	 * The index lists a project page, not an archive. For GitHub we can ask for
	 * a release and the default branch; the other forges in the index get
	 * guessed archive URLs that the installer tries in turn.
	 *
	 * @param array<string,mixed> $entry
	 * @return list<string>
	 */
	private function resolveDownloadUrls(array $entry): array
	{
		$url = is_string($entry['url'] ?? null) ? $entry['url'] : '';
		if ($url === '') {
			return [];
		}

		$repo = EUGitHub::parseRepoUrl($url);
		if ($repo === null) {
			return EUArchive::candidates($url);
		}

		$urls = [];
		$release = $this->github->latestRelease($repo['owner'], $repo['repo']);
		if ($release !== null) {
			$urls[] = $release['zip'];
		}
		$branch = $this->github->defaultBranch($repo['owner'], $repo['repo']);
		foreach (EUArchive::candidates($url, $branch) as $candidate) {
			$urls[] = $candidate;
		}
		return array_values(array_unique($urls));
	}

	/** @return array<string,array<string,mixed>> */
	private function index(): array
	{
		if ($this->index !== null) {
			return $this->index;
		}
		$this->index = [];

		$data = EUHttp::getJson($this->url);
		if ($data === null) {
			$this->error = EUHttp::$lastError;
			return $this->index;
		}

		// Accept both {"extensions": [...]} and a bare array of entries.
		$entries = is_array($data['extensions'] ?? null) ? $data['extensions'] : $data;
		if (!is_array($entries)) {
			$this->error = 'Unexpected index format at ' . $this->url;
			return $this->index;
		}

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			foreach (['entrypoint', 'name', 'directory'] as $field) {
				$value = $entry[$field] ?? null;
				if (!is_string($value) || $value === '') {
					continue;
				}
				$key = strtolower(preg_replace('/^xExtension-/i', '', $value) ?? $value);
				$this->index[$key] ??= $entry;
			}
		}
		return $this->index;
	}
}
