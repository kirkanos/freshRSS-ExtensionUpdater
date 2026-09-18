<?php

declare(strict_types=1);

/** Small wrapper around the bits of the GitHub API we need. */
final class EUGitHub
{
	private string $token;

	public function __construct(string $token = '')
	{
		$this->token = trim($token);
	}

	/**
	 * @return array{owner:string,repo:string}|null
	 */
	public static function parseRepoUrl(string $url): ?array
	{
		if (!preg_match('~^https?://(?:www\.)?github\.com/([^/\s]+)/([^/\s#?]+)~i', $url, $m)) {
			return null;
		}
		$repo = preg_replace('/\.git$/i', '', $m[2]) ?? $m[2];
		return ['owner' => $m[1], 'repo' => $repo];
	}

	/** @return array<string,string> */
	private function headers(): array
	{
		$headers = ['Accept' => 'application/vnd.github+json'];
		if ($this->token !== '') {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}

	/**
	 * @return array{version:string,zip:string,html_url:string}|null
	 */
	public function latestRelease(string $owner, string $repo): ?array
	{
		$url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode($owner), rawurlencode($repo));
		$data = EUHttp::getJson($url, $this->headers());
		if ($data === null) {
			return null;
		}

		$tag = is_string($data['tag_name'] ?? null) ? $data['tag_name'] : '';
		if ($tag === '') {
			return null;
		}

		// A hand-built .zip asset is more likely to be a ready-to-drop-in
		// extension folder than the auto-generated source tarball.
		$zip = '';
		if (is_array($data['assets'] ?? null)) {
			foreach ($data['assets'] as $asset) {
				$name = is_array($asset) && is_string($asset['name'] ?? null) ? $asset['name'] : '';
				$download = is_array($asset) && is_string($asset['browser_download_url'] ?? null) ? $asset['browser_download_url'] : '';
				if ($download !== '' && preg_match('/\.zip$/i', $name)) {
					$zip = $download;
					break;
				}
			}
		}
		if ($zip === '' && is_string($data['zipball_url'] ?? null)) {
			$zip = $data['zipball_url'];
		}
		if ($zip === '') {
			return null;
		}

		return [
			'version' => $tag,
			'zip' => $zip,
			'html_url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
		];
	}

	public function defaultBranch(string $owner, string $repo): ?string
	{
		$url = sprintf('https://api.github.com/repos/%s/%s', rawurlencode($owner), rawurlencode($repo));
		$data = EUHttp::getJson($url, $this->headers());
		$branch = is_array($data) && is_string($data['default_branch'] ?? null) ? $data['default_branch'] : '';
		return $branch !== '' ? $branch : null;
	}

	public static function branchZipUrl(string $owner, string $repo, string $branch): string
	{
		return sprintf(
			'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
			rawurlencode($owner),
			rawurlencode($repo),
			rawurlencode($branch)
		);
	}

	/**
	 * Reads a metadata.json straight from a branch. $subdir is tried first, then
	 * the repository root — single-extension repos put it in either place.
	 *
	 * @return array{version:string,path:string}|null
	 */
	public function readMetadata(string $owner, string $repo, string $branch, string $subdir): ?array
	{
		$candidates = [];
		if ($subdir !== '') {
			$candidates[] = trim($subdir, '/') . '/metadata.json';
		}
		$candidates[] = 'metadata.json';

		foreach (array_unique($candidates) as $path) {
			$url = sprintf(
				'https://raw.githubusercontent.com/%s/%s/%s/%s',
				rawurlencode($owner),
				rawurlencode($repo),
				rawurlencode($branch),
				implode('/', array_map('rawurlencode', explode('/', $path)))
			);
			$data = EUHttp::getJson($url);
			$version = is_array($data) ? EUVersion::toString($data['version'] ?? null) : '';
			if ($version !== '') {
				return ['version' => $version, 'path' => $path];
			}
		}
		return null;
	}
}
