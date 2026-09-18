<?php

declare(strict_types=1);

/**
 * Derives the update from the repository named in the extension's own
 * metadata.json "url". Covers extensions that are not in any index.
 */
final class EUGitHubSource implements EUSource
{
	private EUGitHub $github;

	public function __construct(EUGitHub $github)
	{
		$this->github = $github;
	}

	public function id(): string
	{
		return 'github';
	}

	public function label(): string
	{
		return 'GitHub';
	}

	public function check(EUExtension $ext): ?EUUpdateInfo
	{
		$repo = EUGitHub::parseRepoUrl($ext->url);
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

		// No usable release: compare against metadata.json on the default branch.
		$branch = $this->github->defaultBranch($repo['owner'], $repo['repo']);
		if ($branch === null) {
			return $release === null ? null : $this->upToDate($info, $release['version']);
		}

		$meta = $this->github->readMetadata($repo['owner'], $repo['repo'], $branch, $ext->dirname);
		if ($meta === null) {
			return $release === null ? null : $this->upToDate($info, $release['version']);
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
