<?php

declare(strict_types=1);

/**
 * One installed extension, as read from its metadata.json on disk.
 *
 * We deliberately parse the file ourselves instead of asking
 * Minz_ExtensionManager: disabled extensions are not in the manager's list, but
 * they still want updates.
 */
final class EUExtension
{
	public string $path;
	public string $dirname;
	public string $name = '';
	public string $entrypoint = '';
	public string $version = '';
	public string $author = '';
	public string $url = '';
	public string $type = 'user';
	/** @var array<string,mixed> */
	public array $metadata = [];

	private function __construct(string $path)
	{
		$this->path = rtrim($path, '/');
		$this->dirname = basename($this->path);
	}

	public static function fromDirectory(string $path): ?self
	{
		$metaFile = rtrim($path, '/') . '/metadata.json';
		if (!is_file($metaFile) || !is_readable($metaFile)) {
			return null;
		}
		$raw = file_get_contents($metaFile);
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return null;
		}

		$ext = new self($path);
		$ext->metadata = $data;
		$ext->name = self::str($data, 'name', $ext->dirname);
		$ext->entrypoint = self::str($data, 'entrypoint', preg_replace('/^xExtension-/', '', $ext->dirname) ?? '');
		$ext->version = EUVersion::toString($data['version'] ?? null);
		$ext->author = self::str($data, 'author', '');
		$ext->url = self::str($data, 'url', '');
		$ext->type = self::str($data, 'type', 'user');
		return $ext;
	}

	/** Stable identity across sources: the entrypoint, case-insensitively. */
	public function key(): string
	{
		return strtolower($this->entrypoint !== '' ? $this->entrypoint : $this->dirname);
	}

	public function isGitCheckout(): bool
	{
		return is_dir($this->path . '/.git') || is_file($this->path . '/.git');
	}

	/** The directory must be replaceable for an in-place update to work. */
	public function isWritable(): bool
	{
		return is_writable($this->path) && is_writable(dirname($this->path));
	}

	/** @param array<string,mixed> $data */
	private static function str(array $data, string $key, string $default): string
	{
		$value = $data[$key] ?? null;
		return is_string($value) && $value !== '' ? $value : $default;
	}
}
