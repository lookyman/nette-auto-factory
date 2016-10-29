<?php
declare(strict_types=1);

namespace Lookyman\Nette\AutoFactory;

use Nette\NotSupportedException;

class ProxyLoader
{
	/**
	 * @param string $dir
	 * @param string $namespace
	 * @throws NotSupportedException
	 */
	public static function register(string $dir, string $namespace)
	{
		if (!function_exists('spl_autoload_register')) {
			throw new NotSupportedException();
		}
		spl_autoload_register(function (string $type) use ($dir, $namespace) {
			if (strpos($type, $namespace) === 0) {
				$type = str_replace('\\', '', substr($type, strlen($namespace) + 1));
				if (file_exists($file = $dir . '/' . $type . '.php')) {
					include $file;
				}
			}
		});
	}
}
