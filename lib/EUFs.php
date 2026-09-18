<?php

declare(strict_types=1);

/** Recursive filesystem helpers used by the installer. */
final class EUFs
{
	public static function makeTempDir(string $prefix): ?string
	{
		$base = EUPaths::stateDir();
		for ($i = 0; $i < 5; $i++) {
			$dir = $base . '/' . $prefix . bin2hex(random_bytes(6));
			if (!file_exists($dir) && @mkdir($dir, 0777, true)) {
				return $dir;
			}
		}
		return null;
	}

	public static function removeTree(string $path): bool
	{
		if (is_link($path) || is_file($path)) {
			return @unlink($path);
		}
		if (!is_dir($path)) {
			return true;
		}

		$entries = scandir($path);
		if ($entries === false) {
			return false;
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (!self::removeTree($path . '/' . $entry)) {
				return false;
			}
		}
		return @rmdir($path);
	}

	/** Removes everything inside $path but keeps $path itself. */
	public static function emptyDir(string $path): bool
	{
		$entries = scandir($path);
		if ($entries === false) {
			return false;
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (!self::removeTree($path . '/' . $entry)) {
				return false;
			}
		}
		return true;
	}

	/** Copies the contents of $source into an existing $destination. */
	public static function copyChildren(string $source, string $destination): bool
	{
		$entries = scandir($source);
		if ($entries === false) {
			return false;
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (!self::copyTree($source . '/' . $entry, $destination . '/' . $entry)) {
				return false;
			}
		}
		return true;
	}

	public static function copyTree(string $source, string $destination): bool
	{
		if (is_link($source)) {
			// Extension archives should not contain symlinks; skipping one is
			// safer than following it out of the target directory.
			return true;
		}
		if (is_file($source)) {
			return @copy($source, $destination);
		}
		if (!is_dir($source)) {
			return false;
		}
		if (!is_dir($destination) && !@mkdir($destination, 0777, true)) {
			return false;
		}

		$entries = scandir($source);
		if ($entries === false) {
			return false;
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (!self::copyTree($source . '/' . $entry, $destination . '/' . $entry)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Depth-limited search for the directory holding the extension we are
	 * updating. Archives come in three shapes: the extension folder itself, a
	 * repo with the folder one level down, or a monorepo with many of them.
	 */
	public static function findExtensionDir(string $root, EUExtension $target, int $maxDepth = 3): ?string
	{
		$candidates = [];
		self::collectExtensionDirs($root, 0, $maxDepth, $candidates);

		foreach ($candidates as $dir => $key) {
			if ($key === $target->key()) {
				return $dir;
			}
		}

		// Accept a differently named folder only when the archive held exactly
		// one extension — with several, we would be guessing.
		return count($candidates) === 1 ? (string)array_key_first($candidates) : null;
	}

	/** @param array<string,string> $candidates */
	private static function collectExtensionDirs(string $dir, int $depth, int $maxDepth, array &$candidates): void
	{
		if ($depth > $maxDepth) {
			return;
		}
		$candidate = EUExtension::fromDirectory($dir);
		if ($candidate !== null && is_file($dir . '/extension.php')) {
			$candidates[$dir] = $candidate->key();
			return; // Extensions do not nest.
		}

		$entries = scandir($dir);
		if ($entries === false) {
			return;
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === '.git') {
				continue;
			}
			$child = $dir . '/' . $entry;
			if (is_dir($child) && !is_link($child)) {
				self::collectExtensionDirs($child, $depth + 1, $maxDepth, $candidates);
			}
		}
	}
}
