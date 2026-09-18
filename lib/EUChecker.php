<?php

declare(strict_types=1);

/**
 * Runs every configured source over every installed extension and caches the
 * outcome, so opening the admin page does not hammer GitHub on each reload.
 */
final class EUChecker
{
	private EURegistry $registry;
	/** @var list<EUSource> */
	private array $sources;
	private int $cacheTtl;
	private ?int $checkedAt = null;
	/** @var list<string> */
	private array $errors = [];

	/** @param list<EUSource> $sources */
	public function __construct(EURegistry $registry, array $sources, int $cacheTtlSeconds = 21600)
	{
		$this->registry = $registry;
		$this->sources = $sources;
		$this->cacheTtl = max(0, $cacheTtlSeconds);
	}

	public function checkedAt(): ?int
	{
		return $this->checkedAt;
	}

	/** @return list<string> */
	public function errors(): array
	{
		return array_values(array_unique($this->errors));
	}

	/**
	 * @return array<string,array{ext:EUExtension,info:EUUpdateInfo|null}>
	 */
	public function results(bool $force = false): array
	{
		$extensions = $this->registry->all();

		$cached = $force ? null : $this->readCache();
		if ($cached !== null) {
			$this->checkedAt = $cached['checked_at'];
			return $this->hydrate($extensions, $cached['entries']);
		}

		$entries = [];
		$results = [];
		foreach ($extensions as $key => $ext) {
			$info = $this->best($ext);
			$results[$key] = ['ext' => $ext, 'info' => $info];
			if ($info !== null) {
				$entries[$key] = [
					'source' => $info->sourceId,
					'source_label' => $info->sourceLabel,
					'remote_version' => $info->remoteVersion,
					'download_url' => $info->downloadUrl,
					'download_fallbacks' => $info->downloadFallbacks,
					'release_url' => $info->releaseUrl,
					'note' => $info->note,
					'local_version' => $ext->version,
				];
			}
		}

		foreach ($this->sources as $source) {
			$problem = $source->error();
			if ($problem !== null) {
				$this->errors[] = $problem;
			}
		}

		$this->checkedAt = time();
		// A run that hit an unreachable index or an exhausted API quota looks
		// exactly like "everything is up to date". Caching that would hide the
		// real state until the TTL expires, so leave the cache alone and let
		// the next visit try again.
		if ($this->errors === []) {
			$this->writeCache($entries);
		}
		return $results;
	}

	private function best(EUExtension $ext): ?EUUpdateInfo
	{
		$fallback = null;
		foreach ($this->sources as $source) {
			try {
				$info = $source->check($ext);
			} catch (Throwable $e) {
				$this->errors[] = sprintf('%s / %s: %s', $source->label(), $ext->name, $e->getMessage());
				continue;
			}
			if ($info === null) {
				continue;
			}
			// An installable update wins immediately; anything else is only a
			// fallback so a later source still gets its chance.
			if ($info->canInstall()) {
				return $info;
			}
			$fallback ??= $info;
			if ($info->updateAvailable) {
				$fallback = $info;
			}
		}
		return $fallback;
	}

	/**
	 * @param array<string,EUExtension> $extensions
	 * @param array<string,array<string,mixed>> $entries
	 * @return array<string,array{ext:EUExtension,info:EUUpdateInfo|null}>
	 */
	private function hydrate(array $extensions, array $entries): array
	{
		$results = [];
		foreach ($extensions as $key => $ext) {
			$entry = $entries[$key] ?? null;
			if ($entry === null) {
				$results[$key] = ['ext' => $ext, 'info' => null];
				continue;
			}

			$info = new EUUpdateInfo((string)($entry['source'] ?? ''), (string)($entry['source_label'] ?? ''));
			$info->remoteVersion = (string)($entry['remote_version'] ?? '');
			$info->downloadUrl = (string)($entry['download_url'] ?? '');
			$info->downloadFallbacks = is_array($entry['download_fallbacks'] ?? null) ? $entry['download_fallbacks'] : [];
			$info->releaseUrl = (string)($entry['release_url'] ?? '');
			$info->note = (string)($entry['note'] ?? '');
			// Re-evaluate against the version on disk: the extension may have
			// been updated by hand since the cache was written.
			$info->updateAvailable = $info->note === 'git-pull-required'
				? ($entry['local_version'] ?? null) === $ext->version && (bool)$entry['remote_version']
				: EUVersion::isNewer($info->remoteVersion, $ext->version);

			$results[$key] = ['ext' => $ext, 'info' => $info];
		}
		return $results;
	}

	/**
	 * @return array{checked_at:int,entries:array<string,array<string,mixed>>}|null
	 */
	private function readCache(): ?array
	{
		if ($this->cacheTtl === 0) {
			return null;
		}
		$file = EUPaths::cacheFile();
		if (!is_file($file)) {
			return null;
		}
		$raw = file_get_contents($file);
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !is_array($data['entries'] ?? null)) {
			return null;
		}
		$checkedAt = (int)($data['checked_at'] ?? 0);
		if ($checkedAt <= 0 || $checkedAt + $this->cacheTtl < time()) {
			return null;
		}
		return ['checked_at' => $checkedAt, 'entries' => $data['entries']];
	}

	/** @param array<string,array<string,mixed>> $entries */
	private function writeCache(array $entries): void
	{
		$payload = json_encode(['checked_at' => time(), 'entries' => $entries]);
		if ($payload === false) {
			return;
		}
		@file_put_contents(EUPaths::cacheFile(), $payload, LOCK_EX);
	}

	public static function clearCache(): void
	{
		@unlink(EUPaths::cacheFile());
	}
}
