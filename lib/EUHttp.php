<?php

declare(strict_types=1);

/**
 * Minimal HTTP client.
 *
 * FreshRSS has no stable HTTP helper across the versions we want to support, so
 * we use cURL when it is there and fall back to the stream wrapper otherwise.
 */
final class EUHttp
{
	public const USER_AGENT = 'FreshRSS-ExtensionUpdater/0.1';

	/** @var string|null Last transport level error, for surfacing in the UI. */
	public static ?string $lastError = null;

	/** HTTP status of the last response, 0 when the request never completed. */
	public static int $lastStatus = 0;

	/** @var array<string,string> Response headers of the last response, lower-cased. */
	public static array $lastHeaders = [];

	/**
	 * @param array<string,string> $headers
	 */
	public static function get(string $url, array $headers = [], int $timeout = 20): ?string
	{
		self::$lastError = null;
		self::$lastStatus = 0;
		self::$lastHeaders = [];

		if (!self::isAllowedUrl($url)) {
			self::$lastError = 'Refused non-https URL: ' . $url;
			return null;
		}

		$headerLines = ['User-Agent: ' . self::USER_AGENT];
		foreach ($headers as $name => $value) {
			$headerLines[] = $name . ': ' . $value;
		}

		if (function_exists('curl_init')) {
			return self::getCurl($url, $headerLines, $timeout);
		}
		return self::getStream($url, $headerLines, $timeout);
	}

	/** Streams a URL straight to disk; returns false on any failure. */
	public static function download(string $url, string $destination, int $timeout = 120): bool
	{
		self::$lastError = null;

		if (!self::isAllowedUrl($url)) {
			self::$lastError = 'Refused non-https URL: ' . $url;
			return false;
		}

		$handle = fopen($destination, 'wb');
		if ($handle === false) {
			self::$lastError = 'Cannot write to ' . $destination;
			return false;
		}

		try {
			if (function_exists('curl_init')) {
				$ok = self::downloadCurl($url, $handle, $timeout);
			} else {
				$body = self::getStream($url, ['User-Agent: ' . self::USER_AGENT], $timeout);
				$ok = $body !== null && fwrite($handle, $body) !== false;
			}
		} finally {
			fclose($handle);
		}

		if (!$ok || filesize($destination) === 0) {
			@unlink($destination);
			self::$lastError ??= 'Empty response from ' . $url;
			return false;
		}
		return true;
	}

	/**
	 * Only https, and only to a hostname — this blocks file://, ftp:// and the
	 * usual SSRF shortcuts into the host network. Update sources are user
	 * supplied, so they get no more trust than that.
	 */
	public static function isAllowedUrl(string $url): bool
	{
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return false;
		}
		if (($parts['scheme'] ?? '') !== 'https') {
			return false;
		}
		$host = $parts['host'] ?? '';
		if ($host === '') {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
		}
		return true;
	}

	/** @param list<string> $headerLines */
	private static function getCurl(string $url, array $headerLines, int $timeout): ?string
	{
		$ch = curl_init($url);
		if ($ch === false) {
			self::$lastError = 'curl_init failed';
			return null;
		}
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER => $headerLines,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
		]);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line): int {
			$parts = explode(':', $line, 2);
			if (count($parts) === 2) {
				self::$lastHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
			return strlen($line);
		});
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		self::$lastStatus = $status;
		if ($body === false) {
			self::$lastError = 'cURL: ' . curl_error($ch);
			curl_close($ch);
			return null;
		}
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			self::$lastError = 'HTTP ' . $status . ' for ' . $url;
			return null;
		}
		return (string)$body;
	}

	/** @param resource $handle */
	private static function downloadCurl(string $url, $handle, int $timeout): bool
	{
		$ch = curl_init($url);
		if ($ch === false) {
			self::$lastError = 'curl_init failed';
			return false;
		}
		curl_setopt_array($ch, [
			CURLOPT_FILE => $handle,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT],
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
		]);
		$ok = curl_exec($ch) !== false;
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (!$ok) {
			self::$lastError = 'cURL: ' . curl_error($ch);
		} elseif ($status < 200 || $status >= 300) {
			self::$lastError = 'HTTP ' . $status . ' for ' . $url;
			$ok = false;
		}
		curl_close($ch);
		return $ok;
	}

	/** @param list<string> $headerLines */
	private static function getStream(string $url, array $headerLines, int $timeout): ?string
	{
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'header' => implode("\r\n", $headerLines),
				'timeout' => $timeout,
				'follow_location' => 1,
				'max_redirects' => 5,
				'ignore_errors' => true,
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);
		$body = @file_get_contents($url, false, $context);
		foreach ($http_response_header ?? [] as $line) {
			if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
				self::$lastStatus = (int)$m[1];
				continue;
			}
			$parts = explode(':', $line, 2);
			if (count($parts) === 2) {
				self::$lastHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
		}
		if ($body === false) {
			self::$lastError = 'Request failed: ' . $url;
			return null;
		}
		return $body;
	}

	/** @return array<mixed>|null */
	public static function getJson(string $url, array $headers = [], int $timeout = 20): ?array
	{
		$body = self::get($url, $headers, $timeout);
		if ($body === null) {
			return null;
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			self::$lastError = 'Invalid JSON from ' . $url;
			return null;
		}
		return $data;
	}
}
