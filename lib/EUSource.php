<?php

declare(strict_types=1);

/** A place that can tell us the current version of an extension. */
interface EUSource
{
	public function id(): string;

	public function label(): string;

	/** Null when this source knows nothing about the extension. */
	public function check(EUExtension $ext): ?EUUpdateInfo;
}
