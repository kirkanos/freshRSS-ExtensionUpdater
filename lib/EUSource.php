<?php

declare(strict_types=1);

/** A place that can tell us the current version of an extension. */
interface EUSource
{
	public function id(): string;

	public function label(): string;

	/** Null when this source knows nothing about the extension. */
	public function check(EUExtension $ext): ?EUUpdateInfo;

	/**
	 * A problem that made this source unreliable for the whole run, such as an
	 * unreachable index or an exhausted API quota. Null when it worked.
	 *
	 * Reporting this matters more than it looks: a silent failure degrades to
	 * "everything is up to date", which is indistinguishable from success.
	 */
	public function error(): ?string;
}
