<?php
declare(strict_types=1);

namespace Lookyman\Nette\AutoFactory\DI;

use Lookyman\Nette\AutoFactory\IGeneratedFactory;
use Lookyman\Nette\AutoFactory\ProxyLoader;
use Nette\Caching\Storages\DevNullStorage;
use Nette\DI\CompilerExtension;
use Nette\DI\Helpers;
use Nette\Loaders\RobotLoader;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PhpNamespace;
use Nette\Utils\AssertionException;
use Nette\Utils\Validators;

class AutoFactoryExtension extends CompilerExtension
{
	const TAG_FACTORY = 'lookyman.autofactory';

	/**
	 * @var array
	 */
	private $defaults = [
		'scanFor' => null,
		'sourceDirs' => null,
		'proxyNamespace' => 'Lookyman\Nette\AutoFactory\Proxies',
		'proxyDir' => '%appDir%/../temp/proxies',
	];

	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->defaults);

		// load, validate, expand, and create proxy directory
		Validators::assertField($config, 'proxyDir', 'string');
		$this->config['proxyDir'] = $config['proxyDir'] = Helpers::expand($config['proxyDir'], $builder->parameters);
		if (@!mkdir($config['proxyDir']) && !is_dir($config['proxyDir'])) {
			throw new \RuntimeException(sprintf('Cannot create proxy directory %s', $config['proxyDir']));
		}

		// load, validate, and expand source directories
		Validators::assertField($config, 'sourceDirs', 'string|array');
		$sourceDirs = (array) $config['sourceDirs'];
		/** @var ISourceDirsProvider $extension */
		foreach ($this->compiler->getExtensions(ISourceDirsProvider::class) as $extension) {
			$sourceDirs = array_merge($sourceDirs, $extension->getSourceDirs());
		}
		$sourceDirs = array_map(function ($dir) use ($builder) {
			Validators::assert($dir, 'string');
			return Helpers::expand($dir, $builder->parameters);
		}, $sourceDirs);

		// load indexed classes
		$loader = (new RobotLoader())->setCacheStorage(new DevNullStorage());
		$loader->addDirectory($sourceDirs)->rebuild();

		// load and validate scanned classes
		Validators::assertField($config, 'scanFor', 'string|array');
		$scanFor = (array) $config['scanFor'];
		/** @var IScanForProvider $extension */
		foreach ($this->compiler->getExtensions(IScanForProvider::class) as $extension) {
			$scanFor = array_merge($scanFor, $extension->getScanFor());
		}
		array_walk($scanFor, function ($class) {
			Validators::assert($class, 'string');
			if (!class_exists($class) && !interface_exists($class)) {
				throw new AssertionException(sprintf('Class or interface %s does not exist', $class));
			}
		});

		// load and validate proxy namespace
		Validators::assertField($config, 'proxyNamespace', 'string');
		$this->config['proxyNamespace'] = $config['proxyNamespace'] = trim($config['proxyNamespace'], '\\');
		$namespace = new PhpNamespace($config['proxyNamespace']);

		$n = 0;
		foreach (array_keys($loader->getIndexedClasses()) as $class) {
			// is the class one of the ones we're interested in?
			$rc = new \ReflectionClass($class);
			if (!$rc->isInstantiable() || !$this->isSubclassOf($rc, $scanFor)) {
				continue;
			}

			// add dependency
			$builder->addDependency($rc);

			// crudely resolve arguments
			$args = [];
			$comment = '';
			if ($constructor = $rc->getConstructor()) {
				foreach ($constructor->getParameters() as $parameter) {
					if (!($type = $parameter->getType()) || $type->isBuiltin()) {
						$args[] = $arg = Parameter::from($parameter);
						$comment .= $this->generateParameterDocComment($arg);
					}
				}
			}

			// create a factory interface
			$name = str_replace('\\', '', $class) . '___GeneratedFactoryInterface';
			$factory = clone $namespace;
			$factory->addInterface($name)
				->setExtends(IGeneratedFactory::class)
				->addMethod('create')
				->setParameters($args)
				->setReturnType($rc->getName())
				->setComment(rtrim($comment));

			// save it and load it
			$filename = $config['proxyDir'] . '/' . $name . '.php';
			if (file_put_contents($filename, "<?php\ndeclare(strict_types=1);\n\n" . $factory) === false) {
				throw new \RuntimeException(sprintf('Cannot create file %s', $filename));
			}
			include $filename;

			// add factory definition
			$builder->addDefinition($this->prefix('factory.' . $n++))
				->setImplement($config['proxyNamespace'] . '\\' . $name)
				->addTag(self::TAG_FACTORY, [$class]);
		}
	}

	/**
	 * @param ClassType $class
	 */
	public function afterCompile(ClassType $class)
	{
		$config = $this->getConfig();

		$init = $class->getMethod('initialize');
		$body = $init->getBody();
		$init->setBody(ProxyLoader::class . "::register(?, ?);\n", [$config['proxyDir'], $config['proxyNamespace']])
			->addBody($body);
	}

	/**
	 * @param \ReflectionClass $rc
	 * @param array $classes
	 * @return bool
	 */
	private function isSubclassOf(\ReflectionClass $rc, array $classes = []): bool
	{
		foreach ($classes as $class) {
			if ($rc->isSubclassOf($class)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param Parameter $parameter
	 * @return string
	 */
	private function generateParameterDocComment(Parameter $parameter): string
	{
		return '@param '
		. ($parameter->getTypeHint() ?: 'mixed')
		. ($parameter->isOptional() && $parameter->getDefaultValue() === null ? '|null' : '')
		. ' $'
		. $parameter->getName()
		. "\n";
	}
}
