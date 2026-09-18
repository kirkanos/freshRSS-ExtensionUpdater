<?php

declare(strict_types=1);

/**
 * Turns a repository page URL into downloadable ZIP URLs.
 *
 * The official index points at project pages on four different forges, and only
 * GitHub gives us an API to ask for the default branch. For the rest we produce
 * the plausible candidates and let the installer take the first that answers.
 */
final class EUArchive
{
	/** Tried in order when the default branch is unknown. */
	private const BRANCHES = ['main', 'master'];

	/**
	 * @return list<string> ordered candidates, most likely first
	 */
	public static function candidates(string $repoUrl, ?string $branch = null): array
	{
		$parts = parse_url($repoUrl);
		if (!is_array($parts) || ($parts['host'] ?? '') === '') {
			return [];
		}

		$host = strtolower((string)$parts['host']);
		$path = trim((string)($parts['path'] ?? ''), '/');
		$segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
		if (count($segments) < 2) {
			return [];
		}

		$owner = $segments[0];
		$repo = preg_replace('/\.git$/i', '', $segments[1]) ?? $segments[1];
		$branches = $branch !== null && $branch !== '' ? [$branch] : self::BRANCHES;

		$urls = [];
		foreach ($branches as $ref) {
			foreach (self::shapesFor($host) as $shape) {
				$urls[] = $shape($host, $owner, $repo, $ref);
			}
		}
		return array_values(array_unique($urls));
	}

	/**
	 * @return list<callable(string,string,string,string):string>
	 */
	private static function shapesFor(string $host): array
	{
		$github = static fn(string $h, string $o, string $r, string $b): string => sprintf(
			'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
			rawurlencode($o), rawurlencode($r), rawurlencode($b)
		);
		// Forgejo and Gitea, e.g. codeberg.org or a self-hosted instance.
		$forgejo = static fn(string $h, string $o, string $r, string $b): string => sprintf(
			'https://%s/%s/%s/archive/%s.zip',
			$h, rawurlencode($o), rawurlencode($r), rawurlencode($b)
		);
		$gitlab = static fn(string $h, string $o, string $r, string $b): string => sprintf(
			'https://%s/%s/%s/-/archive/%s/%s-%s.zip',
			$h, rawurlencode($o), rawurlencode($r), rawurlencode($b), rawurlencode($r), rawurlencode($b)
		);

		if ($host === 'github.com' || $host === 'www.github.com') {
			return [$github];
		}
		if (strpos($host, 'gitlab') !== false || strpos($host, 'framagit') !== false) {
			return [$gitlab];
		}
		if ($host === 'codeberg.org') {
			return [$forgejo];
		}
		// Unknown forge: Forgejo/Gitea is the more common self-hosted option,
		// so try that first and GitLab second.
		return [$forgejo, $gitlab];
	}
}
