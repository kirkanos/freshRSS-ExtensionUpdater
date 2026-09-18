<?php

declare(strict_types=1);

/**
 * Finds the extensions installed on this instance.
 *
 * FreshRSS moved extensions around between releases (./extensions, plus a
 * third-party path in newer versions), so we collect every candidate root we
 * can identify rather than hard-coding one.
 */
final class EURegistry
{
	/** @var list<string> */
	private array $roots;

	/** @param list<string> $extraRoots */
	public function __construct(string $ownPath, array $extraRoots = [])
	{
		$roots = [dirname(rtrim($ownPath, '/'))];

		foreach (['EXTENSIONS_PATH', 'THIRDPARTY_EXTENSIONS_PATH'] as $constant) {
			if (defined($constant)) {
				$value = constant($constant);
				if (is_string($value) && $value !== '') {
					$roots[] = rtrim($value, '/');
				}
			}
		}
		foreach ($extraRoots as $root) {
			$roots[] = rtrim($root, '/');
		}

		$this->roots = array_values(array_unique(array_filter($roots, 'is_dir')));
	}

	/** @return list<string> */
	public function roots(): array
	{
		return $this->roots;
	}

	/**
	 * @return array<string,EUExtension> keyed by EUExtension::key()
	 */
	public function all(): array
	{
		$found = [];
		foreach ($this->roots as $root) {
			$entries = scandir($root);
			if ($entries === false) {
				continue;
			}
			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$path = $root . '/' . $entry;
				if (!is_dir($path)) {
					continue;
				}
				$ext = EUExtension::fromDirectory($path);
				if ($ext === null) {
					continue;
				}
				// First root wins, so an overriding copy is not shadowed by a stock one.
				$found[$ext->key()] ??= $ext;
			}
		}

		uasort($found, static fn(EUExtension $a, EUExtension $b): int => strcasecmp($a->name, $b->name));
		return $found;
	}
}
