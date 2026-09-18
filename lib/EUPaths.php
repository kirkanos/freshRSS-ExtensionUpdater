<?php

declare(strict_types=1);

/** Where we are allowed to put temporary files, caches and backups. */
final class EUPaths
{
	public static function tmpRoot(): string
	{
		foreach (['TMP_PATH', 'DATA_PATH'] as $constant) {
			if (defined($constant)) {
				$value = constant($constant);
				if (is_string($value) && is_dir($value) && is_writable($value)) {
					return rtrim($value, '/');
				}
			}
		}
		return rtrim(sys_get_temp_dir(), '/');
	}

	public static function stateDir(): string
	{
		$dir = self::tmpRoot() . '/extension-updater';
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		return $dir;
	}

	/**
	 * Backups go to DATA_PATH when available: TMP_PATH is frequently a
	 * container-local /tmp that disappears on the next restart, which is
	 * exactly when a rollback copy is still wanted.
	 */
	public static function backupDir(): string
	{
		$base = defined('DATA_PATH') && is_string(constant('DATA_PATH')) && is_writable(constant('DATA_PATH'))
			? rtrim(constant('DATA_PATH'), '/') . '/extension-updater'
			: self::stateDir();
		if (!is_dir($base)) {
			@mkdir($base, 0777, true);
		}
		$dir = $base . '/backups';
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		return $dir;
	}

	public static function cacheFile(): string
	{
		return self::stateDir() . '/check-cache.json';
	}
}
