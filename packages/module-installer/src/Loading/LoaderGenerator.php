<?php declare(strict_types = 1);

namespace Modette\ModuleInstaller\Loading;

use Composer\Repository\WritableRepositoryInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Modette\Exceptions\Logic\InvalidArgumentException;
use Modette\Exceptions\Logic\InvalidStateException;
use Modette\ModuleInstaller\Configuration\ConfigurationValidator;
use Modette\ModuleInstaller\Configuration\LoaderConfiguration;
use Modette\ModuleInstaller\Configuration\PackageConfiguration;
use Modette\ModuleInstaller\Files\Writer;
use Modette\ModuleInstaller\Resolving\ModuleResolver;
use Modette\ModuleInstaller\Utils\PathResolver;
use Modette\ModuleInstaller\Utils\PluginActivator;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

final class LoaderGenerator
{

	/** @var WritableRepositoryInterface */
	private $repository;

	/** @var Writer */
	private $writer;

	/** @var PathResolver */
	private $pathResolver;

	/** @var ConfigurationValidator */
	private $validator;

	/** @var PackageConfiguration */
	private $rootPackageConfiguration;

	public function __construct(WritableRepositoryInterface $repository, Writer $writer, PathResolver $pathResolver, ConfigurationValidator $validator, PackageConfiguration $rootPackageConfiguration)
	{
		$this->repository = $repository;
		$this->writer = $writer;
		$this->pathResolver = $pathResolver;
		$this->validator = $validator;
		$this->rootPackageConfiguration = $rootPackageConfiguration;
	}

	public function generateLoader(): void
	{
		$loaderConfiguration = $this->rootPackageConfiguration->getLoader();

		if ($loaderConfiguration === null) {
			throw new InvalidStateException(sprintf(
				'Loader should be always available by this moment. Entry point should check if plugin is activated with \'%s\'',
				PluginActivator::class
			));
		}

		$resolver = new ModuleResolver(
			$this->repository,
			$this->pathResolver,
			$this->validator,
			$this->rootPackageConfiguration
		);

		$this->generateClass($loaderConfiguration, $resolver->getResolvedConfigurations());
	}

	/**
	 * @param PackageConfiguration[] $packageConfigurations
	 */
	private function generateClass(LoaderConfiguration $loaderConfiguration, array $packageConfigurations): void
	{
		$fqn = $loaderConfiguration->getClass();
		$lastSlashPosition = strrpos($fqn, '\\');

		if ($lastSlashPosition === false) {
			throw new InvalidArgumentException('Namespace of loader class must be specified.');
		}

		$schema = [];

		foreach ($packageConfigurations as $packageConfiguration) {
			$packageDirRelative = $this->pathResolver->getRelativePath($packageConfiguration->getPackage());

			foreach ($packageConfiguration->getFiles() as $fileConfiguration) {
				// Skip configuration if required package is not installed
				foreach ($fileConfiguration->getRequiredPackages() as $package) {
					if ($this->repository->findPackage($package, new EmptyConstraint()) === null) {
						continue 2;
					}
				}

				$schema[] = [
					'file' => $packageDirRelative . '/' . $fileConfiguration->getFile(),
					'parameters' => $fileConfiguration->getRequiredParameters(),
				];
			}
		}

		if (class_exists($fqn)) {
			if (!is_subclass_of($fqn, Loader::class)) {
				throw new InvalidStateException(sprintf(
					'\'%s\' should be instance of \'%s\'',
					$fqn,
					Loader::class
				));
			}

			$loader = new $fqn();
			assert($loader instanceof Loader);

			if ($loader->getSchema() === $schema) {
				return;
			}
		}

		$classString = substr($fqn, $lastSlashPosition + 1);
		$namespaceString = substr($fqn, 0, $lastSlashPosition);

		$file = new PhpFile();
		$file->setStrictTypes();

		$alias = $classString === 'Loader' ? 'ModuleLoader' : null;
		$namespace = $file->addNamespace($namespaceString)
			->addUse(Loader::class, $alias);

		$class = $namespace->addClass($classString)
			->setExtends(Loader::class)
			->setFinal()
			->setComment('Generated by modette/module-installer');

		$class->addProperty('schema', $schema)
			->setVisibility(ClassType::VISIBILITY_PROTECTED)
			->setComment('@var mixed[]');

		$loaderFilePath = $this->pathResolver->getRootDir() . '/' . $loaderConfiguration->getFile();
		$this->writer->write($loaderFilePath, $file);
	}

}
