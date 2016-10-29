<?php
declare(strict_types=1);

namespace Lookyman\Nette\AutoFactory\DI;

interface IScanForProvider
{
	/**
	 * @return string[]
	 */
	public function getScanFor(): array;
}
