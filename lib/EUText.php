<?php

declare(strict_types=1);

/**
 * Translation for the library classes.
 *
 * They must not depend on FreshRSS directly — that is what keeps them
 * testable without booting the application — so the extension hands them a
 * callable instead. Without one, the English fallback is used.
 */
final class EUText
{
	/** @var callable(string,string):string|null */
	private $translator;

	/** @param callable(string,string):string|null $translator */
	public function __construct(?callable $translator = null)
	{
		$this->translator = $translator;
	}

	/**
	 * @param string $key      key below ext.extension_updater.
	 * @param string $fallback English text, also the sprintf template
	 * @param string ...$args  substituted into the resolved template
	 */
	public function t(string $key, string $fallback, string ...$args): string
	{
		$template = $fallback;
		if ($this->translator !== null) {
			$translated = ($this->translator)($key, $fallback);
			if ($translated !== '') {
				$template = $translated;
			}
		}
		if ($args === []) {
			return $template;
		}
		// A broken translation must not take the page down. PHP 8 throws a
		// ValueError on a placeholder mismatch while PHP 7.4 only warns and
		// returns false, so guard against both.
		try {
			$formatted = @vsprintf($template, $args);
			if (is_string($formatted)) {
				return $formatted;
			}
		} catch (Throwable $e) {
			// Fall through to the English template below.
		}

		try {
			$formatted = @vsprintf($fallback, $args);
			return is_string($formatted) ? $formatted : $fallback;
		} catch (Throwable $e) {
			return $fallback;
		}
	}
}
