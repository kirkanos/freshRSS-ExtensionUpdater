<?php

declare(strict_types=1);

foreach ([
	'EUVersion', 'EUHttp', 'EUExtension', 'EURegistry', 'EUUpdateInfo',
	'EUGitHub', 'EUSource', 'EUIndexSource', 'EUGitHubSource', 'EUGitSource',
	'EUText', 'EURepoUrls', 'EUArchive', 'EUPaths', 'EUFs', 'EUInstaller', 'EUChecker',
] as $euClass) {
	require_once __DIR__ . '/lib/' . $euClass . '.php';
}

final class ExtensionUpdaterExtension extends Minz_Extension
{
	/** Set in init() so the controller can reach configuration and helpers. */
	public static ?ExtensionUpdaterExtension $instance = null;

	/** @var array<string,mixed> */
	private const DEFAULTS = [
		'use_official_index' => true,
		'official_index_url' => EUIndexSource::OFFICIAL_URL,
		'custom_indexes' => '',
		'enable_github' => true,
		'enable_git' => true,
		'github_token' => '',
		'cache_ttl_hours' => 6,
	];

	public function init(): void
	{
		self::$instance = $this;
		$this->registerTranslates();

		// FreshRSS initialises extensions before authentication, so the admin
		// check cannot happen here — it would always fail. Registration is
		// harmless; the controller and the menu hook enforce access themselves.
		$this->registerController('updater');
		if (method_exists($this, 'registerViews')) {
			$this->registerViews();
		}

		$this->safeRegisterHook('menu_admin_entry', [$this, 'adminMenuEntry']);
	}

	public function isAdmin(): bool
	{
		return class_exists('FreshRSS_Auth') && FreshRSS_Auth::hasAccess('admin');
	}

	/** Hooks differ between FreshRSS releases; an unknown one must not fatal. */
	private function safeRegisterHook(string $name, callable $callback): void
	{
		try {
			$this->registerHook($name, $callback);
		} catch (Throwable $e) {
			Minz_Log::debug('ExtensionUpdater: hook "' . $name . '" unavailable: ' . $e->getMessage());
		}
	}

	/** Rendered after authentication, so the access check belongs here. */
	public function adminMenuEntry(): string
	{
		if (!$this->isAdmin()) {
			return '';
		}

		$url = Minz_Url::display(['c' => 'updater', 'a' => 'index']);
		$label = $this->t('menu', 'Extension updates');
		$badge = '';

		$pending = $this->pendingUpdateCount();
		if ($pending > 0) {
			$badge = ' <span class="badge">' . $pending . '</span>';
		}

		return '<li class="item"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
			. htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $badge . '</a></li>';
	}

	/** Cache-only: the menu is rendered on every page and must never do I/O. */
	private function pendingUpdateCount(): int
	{
		$file = EUPaths::cacheFile();
		if (!is_file($file)) {
			return 0;
		}
		$raw = file_get_contents($file);
		$data = $raw === false ? null : json_decode($raw, true);
		if (!is_array($data) || !is_array($data['entries'] ?? null)) {
			return 0;
		}

		$registry = new EURegistry($this->getPath());
		$installed = $registry->all();

		$count = 0;
		foreach ($data['entries'] as $key => $entry) {
			$ext = $installed[$key] ?? null;
			if ($ext === null || !is_array($entry)) {
				continue;
			}
			if (EUVersion::isNewer((string)($entry['remote_version'] ?? ''), $ext->version)) {
				$count++;
			}
		}
		return $count;
	}

	public function checker(bool $includeSelf = true): EUChecker
	{
		$github = new EUGitHub($this->confString('github_token', ''));
		$text = new EUText([$this, 't']);
		$repoUrls = new EURepoUrls();
		$sources = [];

		if ($this->confBool('use_official_index', true)) {
			$index = new EUIndexSource(
				$this->confString('official_index_url', EUIndexSource::OFFICIAL_URL),
				$this->t('source_official', 'Official index'),
				$github,
				$text
			);
			$sources[] = $index;
			$repoUrls->addIndex($index);
		}
		foreach ($this->customIndexUrls() as $url) {
			$index = new EUIndexSource($url, $this->t('source_custom', 'Custom index'), $github, $text);
			$sources[] = $index;
			$repoUrls->addIndex($index);
		}
		// Runs after the indexes: it reaches the repository itself and so sees
		// releases the hand-maintained indexes have not caught up with.
		if ($this->confBool('enable_github', true)) {
			$sources[] = new EUGitHubSource($github, $repoUrls, $text);
		}
		if ($this->confBool('enable_git', true) && EUGitSource::isAvailable()) {
			$sources[] = new EUGitSource();
		}

		$ttl = max(0, $this->confInt('cache_ttl_hours', 6)) * 3600;
		return new EUChecker(new EURegistry($this->getPath()), $sources, $ttl);
	}

	/** @return list<string> */
	public function customIndexUrls(): array
	{
		$raw = $this->confString('custom_indexes', '');
		$urls = [];
		foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
			$line = trim($line);
			if ($line !== '' && EUHttp::isAllowedUrl($line)) {
				$urls[] = $line;
			}
		}
		return $urls;
	}

	public function handleConfigureAction(): void
	{
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$this->saveConf([
				'use_official_index' => self::reqBool('use_official_index') ? '1' : '0',
				'official_index_url' => trim(self::reqString('official_index_url')) ?: EUIndexSource::OFFICIAL_URL,
				'custom_indexes' => self::reqString('custom_indexes'),
				'enable_github' => self::reqBool('enable_github') ? '1' : '0',
				'enable_git' => self::reqBool('enable_git') ? '1' : '0',
				'github_token' => trim(self::reqString('github_token')),
				'cache_ttl_hours' => (string)max(0, (int)self::reqString('cache_ttl_hours')),
			]);
			// Settings change what "up to date" means, so the cache is stale.
			EUChecker::clearCache();
		}
	}

	/**
	 * Minz_Request gained the typed accessors late; fall back to param().
	 * Note paramString()'s second argument is a $plaintext flag, not a default,
	 * so the default is applied here instead.
	 */
	public static function reqString(string $key, string $default = ''): string
	{
		if (method_exists('Minz_Request', 'paramString')) {
			$value = Minz_Request::paramString($key);
			return $value !== '' ? $value : $default;
		}
		$value = Minz_Request::param($key, $default);
		return is_scalar($value) ? (string)$value : $default;
	}

	public static function reqBool(string $key): bool
	{
		if (method_exists('Minz_Request', 'paramBoolean')) {
			return (bool)Minz_Request::paramBoolean($key);
		}
		return (bool)Minz_Request::param($key, false);
	}

	// --- configuration access -------------------------------------------------
	// FreshRSS renamed these accessors between releases; probe instead of
	// assuming, so the extension keeps working on older instances.

	/** @return array<string,mixed> */
	private function allConf(): array
	{
		foreach (['getSystemConfiguration', 'getUserConfiguration'] as $method) {
			if (method_exists($this, $method)) {
				$values = $this->$method();
				if (is_array($values)) {
					return $values;
				}
			}
		}
		return [];
	}

	/** @param array<string,string> $values */
	private function saveConf(array $values): void
	{
		foreach (['setSystemConfiguration', 'setUserConfiguration'] as $method) {
			if (method_exists($this, $method)) {
				$this->$method($values);
				return;
			}
		}
		Minz_Log::warning('ExtensionUpdater: no configuration setter available; settings were not saved.');
	}

	public function confString(string $key, string $default = ''): string
	{
		$value = $this->allConf()[$key] ?? self::DEFAULTS[$key] ?? $default;
		return is_scalar($value) ? (string)$value : $default;
	}

	public function confBool(string $key, bool $default = false): bool
	{
		$values = $this->allConf();
		if (!array_key_exists($key, $values)) {
			return (bool)(self::DEFAULTS[$key] ?? $default);
		}
		return (bool)$values[$key] && $values[$key] !== '0';
	}

	public function confInt(string $key, int $default = 0): int
	{
		$value = $this->allConf()[$key] ?? self::DEFAULTS[$key] ?? $default;
		return is_scalar($value) ? (int)$value : $default;
	}

	/** Translate with an inline fallback, so a missing i18n file is not fatal. */
	public function t(string $key, string $fallback): string
	{
		if (!function_exists('_t')) {
			return $fallback;
		}
		$full = 'ext.extension_updater.' . $key;
		$translated = _t($full);
		return ($translated === '' || $translated === $full) ? $fallback : $translated;
	}
}
