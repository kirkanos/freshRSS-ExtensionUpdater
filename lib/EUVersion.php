<?php

declare(strict_types=1);

/**
 * Version comparison for extension metadata.
 *
 * Extension authors are inconsistent: "1.3", "v2.0.1", "2024-05-01", "0.7-beta".
 * We normalise enough of that for version_compare() to behave sensibly, and fall
 * back to a plain string comparison when a version is not recognisable at all.
 */
final class EUVersion
{
	/**
	 * Metadata files are hand-written and a version is regularly a JSON number
	 * rather than a string ("version": 1.1). Accept any scalar.
	 */
	public static function toString($value): string
	{
		if (is_string($value)) {
			return trim($value);
		}
		if (is_int($value)) {
			return (string)$value;
		}
		if (is_float($value)) {
			// Avoid 1.1 becoming "1.1000000000000001".
			return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
		}
		return '';
	}

	/** Strip decoration so version_compare() sees something it understands. */
	public static function normalize(string $version): string
	{
		$version = trim($version);
		$version = preg_replace('/^[vV]/', '', $version) ?? $version;
		$version = str_replace(['_', ' '], ['.', ''], $version);
		return $version;
	}

	/** A date-shaped version such as 2024-05-01 or 20240501. */
	public static function isDateLike(string $version): bool
	{
		return (bool)preg_match('/^\d{4}[-.]?\d{2}[-.]?\d{2}$/', trim($version));
	}

	/**
	 * True when $remote is strictly newer than $local.
	 *
	 * Unknown or unparseable versions never report an update: we would rather
	 * miss one than offer to overwrite a working extension with an older copy.
	 */
	public static function isNewer(string $remote, string $local): bool
	{
		$remote = self::normalize($remote);
		$local = self::normalize($local);

		if ($remote === '' || $local === '') {
			return false;
		}
		if ($remote === $local) {
			return false;
		}

		if (self::isDateLike($remote) && self::isDateLike($local)) {
			$r = preg_replace('/\D/', '', $remote) ?? '';
			$l = preg_replace('/\D/', '', $local) ?? '';
			return $r > $l;
		}

		// version_compare copes with 1.2.3, 1.2, 1.2-beta, 1.2rc1 …
		if (self::looksNumeric($remote) && self::looksNumeric($local)) {
			return version_compare($remote, $local, '>');
		}

		return false;
	}

	private static function looksNumeric(string $version): bool
	{
		return (bool)preg_match('/^\d+(\.\d+)*/', $version);
	}
}
