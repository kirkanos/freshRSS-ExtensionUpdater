<?php

declare(strict_types=1);

/**
 * For extensions installed as a git clone: ask the remote whether the tracked
 * branch has moved ahead. This reports an update but never a download URL —
 * replacing a working tree with a ZIP would throw the checkout away, so the
 * UI tells the user to pull instead.
 */
final class EUGitSource implements EUSource
{
	public function id(): string
	{
		return 'git';
	}

	public function label(): string
	{
		return 'Git';
	}

	public function error(): ?string
	{
		return null;
	}

	public static function isAvailable(): bool
	{
		if (!function_exists('exec')) {
			return false;
		}
		$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
		return !in_array('exec', $disabled, true);
	}

	public function check(EUExtension $ext): ?EUUpdateInfo
	{
		if (!$ext->isGitCheckout() || !self::isAvailable()) {
			return null;
		}

		$dir = escapeshellarg($ext->path);
		$this->run("git -C {$dir} fetch --quiet --no-tags 2>&1", $fetchStatus);
		if ($fetchStatus !== 0) {
			return null;
		}

		$output = $this->run("git -C {$dir} rev-list --count HEAD..@{u} 2>&1", $status);
		if ($status !== 0) {
			// No upstream configured, or a detached HEAD — nothing to report.
			return null;
		}

		$behind = (int)trim($output);
		$info = new EUUpdateInfo($this->id(), $this->label());
		$info->updateAvailable = $behind > 0;
		$info->remoteVersion = $behind > 0 ? sprintf('%d commit(s) ahead', $behind) : $ext->version;
		$info->note = 'git-pull-required';
		return $info;
	}

	private function run(string $command, ?int &$status): string
	{
		$lines = [];
		$code = 0;
		@exec($command, $lines, $code);
		$status = $code;
		return implode("\n", $lines);
	}
}
