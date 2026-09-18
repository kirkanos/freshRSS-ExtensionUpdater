<?php

declare(strict_types=1);

/**
 * FreshRSS_ActionController rather than Minz_ActionController: it supplies the
 * FreshRSS_View the standard layout expects.
 */
final class FreshExtension_updater_Controller extends FreshRSS_ActionController
{
	private ExtensionUpdaterExtension $ext;

	public function firstAction(): void
	{
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
			return;
		}

		$extension = ExtensionUpdaterExtension::$instance;
		if (!$extension instanceof ExtensionUpdaterExtension) {
			Minz_Error::error(500);
			return;
		}
		$this->ext = $extension;

		FreshRSS_View::prependTitle($extension->t('title', 'Extension updates') . ' · ');
		$this->view->html_url = Minz_Url::display(['c' => 'updater', 'a' => 'index'], 'html', 'root');

		Minz_View::appendStyle($extension->getFileUrl('updater.css', 'css'));
		Minz_View::appendScript($extension->getFileUrl('updater.js', 'js'));

		$this->view->extensionInstance = $extension;
	}

	public function indexAction(): void
	{
		// ?refresh=1 bypasses the cache. A plain GET is enough: re-checking is
		// read-only, and Minz_Request::forward() cannot route back to an
		// extension controller.
		$force = ExtensionUpdaterExtension::reqBool('refresh');
		$checker = $this->ext->checker();

		$this->view->results = $checker->results($force);
		$this->view->checkedAt = $checker->checkedAt();
		$this->view->errors = $checker->errors();
		$this->view->zipAvailable = class_exists('ZipArchive');
	}

	/** POST only: downloads and installs one extension. */
	public function updateAction(): void
	{
		if (!Minz_Request::isPost() || !$this->csrfOk()) {
			Minz_Request::bad(
				$this->ext->t('bad_request', 'Invalid request.'),
				['c' => 'updater', 'a' => 'index']
			);
			return;
		}

		$key = strtolower(trim(ExtensionUpdaterExtension::reqString('key')));
		$checker = $this->ext->checker();
		$results = $checker->results(false);
		$row = $results[$key] ?? null;

		if ($row === null || !($row['info'] instanceof EUUpdateInfo) || !$row['info']->canInstall()) {
			Minz_Request::bad(
				$this->ext->t('no_update', 'No installable update for this extension.'),
				['c' => 'updater', 'a' => 'index']
			);
			return;
		}

		$installer = new EUInstaller();
		$result = $installer->install($row['ext'], $row['info']->downloadCandidates());

		foreach ($installer->log() as $line) {
			Minz_Log::notice('ExtensionUpdater: ' . $line);
		}

		// Versions on disk changed; the next page load must re-check.
		EUChecker::clearCache();

		if ($result['ok']) {
			$message = sprintf(
				$this->ext->t('updated', '%s updated from %s to %s.'),
				$row['ext']->name,
				$row['ext']->version !== '' ? $row['ext']->version : '?',
				$result['version']
			);
			Minz_Request::good($message, ['c' => 'updater', 'a' => 'index']);
		} else {
			// Installer messages are diagnostic English; the full log line is in
			// FreshRSS' log for the admin to read.
			Minz_Request::bad(
				$this->ext->t('update_failed', 'Update failed:') . ' ' . $result['message'],
				['c' => 'updater', 'a' => 'index']
			);
		}
	}

	private function csrfOk(): bool
	{
		if (method_exists('FreshRSS_Auth', 'isCsrfOk')) {
			return FreshRSS_Auth::isCsrfOk();
		}
		$token = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : '';
		$session = class_exists('Minz_Session') ? (string)Minz_Session::paramString('csrf') : '';
		return $token !== '' && $session !== '' && hash_equals($session, $token);
	}
}
