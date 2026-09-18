<?php

declare(strict_types=1);

/**
 * Downloads an extension archive and swaps it in.
 *
 * The old directory is moved aside before the new one is written, and moved
 * back if anything goes wrong — an interrupted update must never leave a
 * half-written extension behind, because FreshRSS would try to load it.
 */
final class EUInstaller
{
	/** Refuse anything implausibly large for an extension. */
	private const MAX_ARCHIVE_BYTES = 64 * 1024 * 1024;

	/** @var list<string> */
	private array $log = [];

	/** @return list<string> */
	public function log(): array
	{
		return $this->log;
	}

	/**
	 * @param list<string> $zipUrls candidates, tried in order until one downloads
	 * @return array{ok:bool,message:string,backup:string,version:string}
	 */
	public function install(EUExtension $ext, array $zipUrls): array
	{
		$this->log = [];

		$zipUrls = array_values(array_filter($zipUrls, 'strlen'));
		if ($zipUrls === []) {
			return $this->fail('No download URL is known for this extension.');
		}

		$precondition = $this->checkPreconditions($ext, $zipUrls);
		if ($precondition !== null) {
			return $this->fail($precondition);
		}

		$work = EUFs::makeTempDir('work-');
		if ($work === null) {
			return $this->fail('Could not create a temporary directory in ' . EUPaths::stateDir());
		}

		try {
			$archive = $work . '/extension.zip';
			$downloaded = false;
			$lastError = 'unknown error';
			foreach ($zipUrls as $zipUrl) {
				$this->note('Downloading ' . $zipUrl);
				if (EUHttp::download($zipUrl, $archive)) {
					$downloaded = true;
					break;
				}
				$lastError = EUHttp::$lastError ?? 'unknown error';
				$this->note('  failed: ' . $lastError);
			}
			if (!$downloaded) {
				return $this->fail('Download failed: ' . $lastError);
			}

			$size = filesize($archive);
			if ($size === false || $size > self::MAX_ARCHIVE_BYTES) {
				return $this->fail('Archive is too large or unreadable.');
			}
			$this->note(sprintf('Downloaded %d bytes', $size));

			$extracted = $work . '/extracted';
			$error = $this->extract($archive, $extracted);
			if ($error !== null) {
				return $this->fail($error);
			}

			$source = EUFs::findExtensionDir($extracted, $ext);
			if ($source === null) {
				return $this->fail('The archive does not contain an extension matching "' . $ext->entrypoint . '".');
			}
			$this->note('Found extension in ' . basename($source));

			$newMeta = EUExtension::fromDirectory($source);
			if ($newMeta === null || !is_file($source . '/extension.php')) {
				return $this->fail('The archive is missing metadata.json or extension.php.');
			}
			if ($newMeta->version === '') {
				return $this->fail('The archive declares no version; refusing to install it.');
			}

			return $this->swap($ext, $source, $newMeta->version);
		} finally {
			EUFs::removeTree($work);
		}
	}

	/** @param list<string> $zipUrls */
	private function checkPreconditions(EUExtension $ext, array $zipUrls): ?string
	{
		if (!class_exists('ZipArchive')) {
			return 'PHP extension "zip" is not available; ZIP based updates are impossible.';
		}
		foreach ($zipUrls as $zipUrl) {
			if (!EUHttp::isAllowedUrl($zipUrl)) {
				return 'Refusing to download from a non-https URL: ' . $zipUrl;
			}
		}
		if (!$ext->isWritable()) {
			return 'The directory ' . $ext->path . ' is not writable by the web server.';
		}
		if ($ext->isGitCheckout()) {
			return 'This extension is a git checkout; update it with "git pull" instead.';
		}
		return null;
	}

	/** Returns an error message, or null on success. */
	private function extract(string $archive, string $destination): ?string
	{
		$zip = new ZipArchive();
		if ($zip->open($archive) !== true) {
			return 'The downloaded file is not a valid ZIP archive.';
		}

		// Reject path traversal and absolute paths before writing anything.
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				$zip->close();
				return 'Unreadable entry in the archive.';
			}
			if (strpos($name, '/') === 0 || strpos($name, '..') !== false || strpos($name, "\0") !== false) {
				$zip->close();
				return 'The archive contains an unsafe path: ' . $name;
			}
		}

		if (!is_dir($destination) && !@mkdir($destination, 0777, true)) {
			$zip->close();
			return 'Could not create ' . $destination;
		}

		$ok = $zip->extractTo($destination);
		$zip->close();

		return $ok ? null : 'Extraction failed.';
	}

	/**
	 * @return array{ok:bool,message:string,backup:string,version:string}
	 */
	private function swap(EUExtension $ext, string $source, string $newVersion): array
	{
		$backup = EUPaths::backupDir() . '/' . $ext->dirname . '-' . $ext->version . '-' . date('Ymd-His');
		if (file_exists($backup)) {
			$backup .= '-' . bin2hex(random_bytes(3));
		}

		$this->note('Backing up to ' . $backup);
		if (!EUFs::copyTree($ext->path, $backup)) {
			EUFs::removeTree($backup);
			return $this->fail('Could not create a backup; aborting without touching the extension.');
		}

		// Move the live directory aside rather than deleting it, so a failure
		// during the copy is still recoverable on the same filesystem.
		$aside = $ext->path . '.eu-old-' . bin2hex(random_bytes(4));
		if (!@rename($ext->path, $aside)) {
			return $this->fail('Could not move the current extension out of the way.');
		}

		if (!EUFs::copyTree($source, $ext->path)) {
			EUFs::removeTree($ext->path);
			@rename($aside, $ext->path);
			return $this->fail('Copying the new version failed; the previous version was restored.');
		}

		$verified = EUExtension::fromDirectory($ext->path);
		if ($verified === null || !is_file($ext->path . '/extension.php')) {
			EUFs::removeTree($ext->path);
			@rename($aside, $ext->path);
			return $this->fail('The installed directory failed verification; the previous version was restored.');
		}

		EUFs::removeTree($aside);
		$this->note('Installed version ' . $verified->version);

		return [
			'ok' => true,
			'message' => sprintf('%s updated from %s to %s.', $ext->name, $ext->version ?: '?', $verified->version ?: $newVersion),
			'backup' => $backup,
			'version' => $verified->version ?: $newVersion,
		];
	}

	/** @return array{ok:bool,message:string,backup:string,version:string} */
	private function fail(string $message): array
	{
		$this->note('ERROR: ' . $message);
		return ['ok' => false, 'message' => $message, 'backup' => '', 'version' => ''];
	}

	private function note(string $line): void
	{
		$this->log[] = $line;
	}
}
