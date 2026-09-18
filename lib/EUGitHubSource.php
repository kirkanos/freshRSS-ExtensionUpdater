<?php

declare(strict_types=1);

/**
 * Derives the update from the repository named in the extension's own
 * metadata.json "url". Covers extensions that are not in any index.
 */
final class EUGitHubSource implements EUSource
{
	private EUGitHub $github;
	private ?EURepoUrls $repoUrls;
	private EUText $text;

	public function __construct(EUGitHub $github, ?EURepoUrls $repoUrls = null, ?EUText $text = null)
	{
		$this->github = $github;
		$this->repoUrls = $repoUrls;
		$this->text = $text ?? new EUText();
	}

	public function id(): string
	{
		return 'github';
	}

	public function label(): string
	{
		return 'GitHub';
	}

	public function error(): ?string
	{
		if (!$this->github->isRateLimited()) {
			return null;
		}
		$reset = $this->github->rateLimitResetAt();
		$when = $reset !== null
			? date('H:i', $reset)
			: $this->text->t('error.unknown_time', 'an unknown time');

		return $this->text->t(
			'error.github_rate_limit',
			'GitHub API rate limit exhausted; update checks fall back to the indexes until %s. Add a GitHub token in the extension settings to raise the limit from 60 to 5000 requests per hour.',
			$when
		);
	}

	public function check(EUExtension $ext): ?EUUpdateInfo
	{
		$url = $this->repoUrls !== null ? $this->repoUrls->resolve($ext) : $ext->url;
		$repo = EUGitHub::parseRepoUrl($url);
		if ($repo === null) {
			return null;
		}

		$info = new EUUpdateInfo($this->id(), $this->label());

		$release = $this->github->latestRelease($repo['owner'], $repo['repo']);
		if ($release !== null && EUVersion::isNewer($release['version'], $ext->version)) {
			$info->remoteVersion = $release['version'];
			$info->downloadUrl = $release['zip'];
			$info->releaseUrl = $release['html_url'];
			$info->updateAvailable = true;
			return $info;
		}

		// A release exists but is not newer: trust it and stop. Probing the
		// default branch as well would double this source's API calls for
		// every up-to-date extension, for little gain.
		if ($release !== null) {
			return $this->upToDate($info, $release['version']);
		}

		// No release at all: compare against metadata.json on the default branch.
		$branch = $this->github->defaultBranch($repo['owner'], $repo['repo']);
		if ($branch === null) {
			return null;
		}

		$meta = $this->github->readMetadata($repo['owner'], $repo['repo'], $branch, $ext->dirname);
		if ($meta === null) {
			return null;
		}

		$info->remoteVersion = $meta['version'];
		$info->releaseUrl = sprintf('https://github.com/%s/%s', $repo['owner'], $repo['repo']);
		$info->updateAvailable = EUVersion::isNewer($meta['version'], $ext->version);
		if ($info->updateAvailable) {
			$info->downloadUrl = EUGitHub::branchZipUrl($repo['owner'], $repo['repo'], $branch);
		}
		return $info;
	}

	private function upToDate(EUUpdateInfo $info, string $version): EUUpdateInfo
	{
		$info->remoteVersion = $version;
		$info->updateAvailable = false;
		return $info;
	}
}
