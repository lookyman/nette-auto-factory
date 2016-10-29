<?php
declare(strict_types=1);

namespace Lookyman\Nette\AutoFactory\DI;

interface ISourceDirsProvider
{
	/**
	 * @return string[]
	 */
	public function getSourceDirs(): array;
}
