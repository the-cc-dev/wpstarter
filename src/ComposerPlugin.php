<?php declare(strict_types=1);
/*
 * This file is part of the WP Starter package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WeCodeMore\WpStarter;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Util;
use WeCodeMore\WpStarter\Step;
use WeCodeMore\WpStarter\Util\Io;

/**
 * Composer plugin class to run all the WP Starter steps on Composer install or update and also adds
 * 'wpstarter' command to allow doing same thing "on demand".
 */
final class ComposerPlugin implements
    PluginInterface,
    EventSubscriberInterface,
    Capable,
    CommandProvider
{

    const EXTRA_KEY = 'wpstarter';
    const EXTENSIONS_TYPE = 'wpstarter-extension';

    /**
     * @var bool
     */
    private static $autoload = false;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var Util\Locator
     */
    private $locator;

    /**
     * @var bool
     */
    private $autorun = false;

    /**
     * @var bool
     */
    private $update = false;

    /**
     * @var PackageInterface[]
     */
    private $updatedPackages = [];

    /**
     * A very simple PSR-4 compatible loader function.
     *
     * @param string $namespace
     * @param string $dir
     * @return callable
     */
    public static function psr4LoaderFor(string $namespace, string $dir): callable
    {
        return function (string $class) use ($namespace, $dir) {
            if (stripos($class, $namespace) === 0) {
                $file = substr(str_replace('\\', '/', $class), strlen($namespace)) . '.php';
                require_once $dir . $file;
            }
        };
    }

    /**
     * WP Starter is required from Composer, which means it is deployed with WordPress, and so
     * any autoloading setting that WP Starter declares will "pollute" the Composer autoloader that
     * is loaded at every WordPress request.
     * For this reason we keep in the autoload section of composer.json only the needed bare-minimum
     * (basically this class) then we register a simple PSR-4 loader for the rest.
     *
     * @return void
     */
    public static function setupAutoload()
    {
        static::$autoload or spl_autoload_register(
            static::psr4LoaderFor(__NAMESPACE__, __DIR__),
            true,
            true
        );

        static::$autoload = true;
    }

    /**
     * phpcs:disable Inpsyde.CodeQuality.NoAccessors
     */
    public static function getSubscribedEvents(): array
    {
        // phpcs:enable

        return [
            'post-install-cmd' => 'autorunInstall',
            'post-update-cmd' => 'autorunUpdate',
            'pre-package-update' => 'prePackage',
            'pre-package-install' => 'prePackage',
        ];
    }

    /**
     * @return array
     */
    public static function defaultSteps(): array
    {
        return [
            Step\CheckPathStep::NAME => Step\CheckPathStep::class,
            Step\WpConfigStep::NAME => Step\WpConfigStep::class,
            Step\IndexStep::NAME => Step\IndexStep::class,
            Step\FlushEnvCacheStep::NAME => Step\FlushEnvCacheStep::class,
            Step\MuLoaderStep::NAME => Step\MuLoaderStep::class,
            Step\EnvExampleStep::NAME => Step\EnvExampleStep::class,
            Step\DropinsStep::NAME => Step\DropinsStep::class,
            Step\MoveContentStep::NAME => Step\MoveContentStep::class,
            Step\ContentDevStep::NAME => Step\ContentDevStep::class,
            Step\WpCliConfigStep::NAME => Step\WpCliConfigStep::class,
            Step\WpCliCommandsStep::NAME => Step\WpCliCommandsStep::class,
        ];
    }

    /**
     * phpcs:disable Inpsyde.CodeQuality.NoAccessors
     */
    public function getCapabilities(): array
    {
        // phpcs:enable

        return [CommandProvider::class => __CLASS__];
    }

    /**
     * phpcs:disable Inpsyde.CodeQuality.NoAccessors
     */
    public function getCommands(): array
    {
        // phpcs:enable

        return [new WpStarterCommand()];
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param PackageEvent $event
     */
    public function prePackage(PackageEvent $event)
    {
        $operation = $event->getOperation();

        $package = null;
        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } elseif ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        }

        $package and $this->updatedPackages[$package->getName()] = $package;
    }

    /**
     * @param Event $event
     */
    public function autorunInstall(Event $event)
    {
        $this->autorun = true;
        static::setupAutoload();
        if ($this->composer->getPackage()->getType() === self::EXTENSIONS_TYPE) {
            return;
        }

        $this->run(Util\SelectedStepsFactory::autorun());
    }

    /**
     * @param Event $event
     */
    public function autorunUpdate(Event $event)
    {
        $this->update = true;
        $this->autorunInstall($event);
    }

    /**
     * @param Util\SelectedStepsFactory $factory
     */
    public function run(Util\SelectedStepsFactory $factory)
    {
        $config = $this->prepareRun($factory);

        try {
            $requireWp = $config[Config::REQUIRE_WP]->not(false);
            $fallbackVer = $config[Config::WP_VERSION]->unwrapOrFallback('');
            $wpVersion = $this->checkWp($requireWp, $fallbackVer, $config);
            if (!$wpVersion && $requireWp) {
                $this->autorun or exit(1);

                return;
            }

            $commandMode = $factory->isSelectedCommandMode();
            $commandMode or $this->logo();

            $skipDbCheck = $config[Config::SKIP_DB_CHECK];
            if ($skipDbCheck->notEmpty() && $skipDbCheck->not(true)) {
                $this->locator->dbChecker()->check();
            }

            $steps = $factory->selectAndFactory($this->locator, $this->composer);
            if (!$steps) {
                $message = $factory->lastFatalError() ?: 'Nothing to run.';
                throw new \Exception($message);
            }

            $this->maybePrintFactoryError($factory, $this->locator->io());

            $stepRunner = new Step\Steps($this->locator, $this->composer, $commandMode);
            foreach ($steps as $step) {
                $stepRunner->addStep($step);
            }

            $stepRunner->run($this->locator->config(), $this->locator->paths());

            $commandMode and exit(0);
        } catch (\Throwable $throwable) {
            $lines = [$throwable->getMessage()];
            if ($this->io->isVerbose()) {
                $lines = explode("\n", $throwable->getTraceAsString());
                array_unshift($lines, '');
                array_unshift($lines, $throwable->getMessage());
            }

            $this->locator->io()->writeErrorBlock(...$lines);
            $this->autorun or exit(1);
        }
    }

    /**
     * @param Util\SelectedStepsFactory $factory
     * @return Config
     * @throws \Exception
     */
    private function prepareRun(Util\SelectedStepsFactory $factory): Config
    {
        $filesystem = new Filesystem();

        $requirements = new Util\Requirements(
            $this->composer,
            $this->io,
            $filesystem,
            $factory,
            $this->autorun,
            $this->update,
            $this->updatedPackages
        );

        $config = $requirements->config();

        $autoload = $config[Config::AUTOLOAD]->unwrapOrFallback();
        if ($autoload && is_file($autoload)) {
            require_once $autoload;
        }

        $this->locator = new Util\Locator($requirements, $this->composer, $this->io, $filesystem);
        $this->loadExtensions();

        return $config;
    }

    /**
     * @param bool $requireWp
     * @param string $fallbackVer
     * @param Config $config
     * @return string
     */
    private function checkWp(bool $requireWp, string $fallbackVer, Config $config): string
    {
        $wpVersion = '';
        if ($requireWp) {
            $wpVersionDiscover = new Util\WpVersion(
                $this->locator->packageFinder(),
                $this->locator->io(),
                $fallbackVer
            );
            $wpVersion = $wpVersionDiscover->discover();
        }

        if (!$wpVersion && $requireWp) {
            return '';
        }

        // If WP version found and no version is in configs, let's set it with the finding.
        if ($wpVersion && !$fallbackVer) {
            $config[Config::WP_VERSION] = $fallbackVer;
        }

        return $wpVersion;
    }

    /**
     * @return void
     */
    private function loadExtensions()
    {
        $packages = $this->locator->packageFinder()->findByType(self::EXTENSIONS_TYPE);

        foreach ($packages as $package) {
            $this->loadExtensionAutoload($package);
        }
    }

    /**
     * @param PackageInterface $package
     * @return void
     */
    private function loadExtensionAutoload(PackageInterface $package)
    {
        $autoload = $package->getExtra()['wpstarter-autoload'] ?? null;
        if (!$autoload || !is_array($autoload)) {
            return;
        }

        $files = $autoload['files'] ?? [];
        $psr4 = $autoload['psr-4'] ?? [];
        is_array($files) or $files = [];
        is_array($psr4) or $psr4 = [];

        if (!$files && !$psr4) {
            return;
        }

        $packagePath = $this->locator->packageFinder()->findPathOf($package);
        $filesystem = $this->locator->composerFilesystem();

        foreach ($files as $file) {
            if (is_string($file)) {
                $fullpath = $filesystem->normalizePath("{$packagePath}/{$file}");
                file_exists($fullpath) and require_once $fullpath;
            }
        }

        foreach ($psr4 as $namespace => $dir) {
            if (!is_string($namespace) || !is_string($dir)) {
                continue;
            }
            $fullpath = $filesystem->normalizePath("{$packagePath}/{$dir}");
            is_dir($fullpath) and spl_autoload_register(
                static::psr4LoaderFor(rtrim($namespace, '\\/'), $fullpath),
                true,
                true
            );
        }
    }

    /**
     * @return void
     */
    private function logo()
    {
        // phpcs:disable
        $logo = <<<LOGO
<fg=magenta>    __      __ ___  </><fg=yellow>   ___  _____  _    ___  _____  ___  ___  </>
<fg=magenta>    \ \    / /| _ \ </><fg=yellow>  / __||_   _|/_\  | _ \|_   _|| __|| _ \ </>
<fg=magenta>     \ \/\/ / |  _/ </><fg=yellow>  \__ \  | | / _ \ |   /  | |  | _| |   / </>
<fg=magenta>      \_/\_/  |_|   </><fg=yellow>  |___/  |_|/_/ \_\|_|_\  |_|  |___||_|_\ </>
LOGO;
        // phpcs:enable

        $this->io->write("\n{$logo}\n");
    }

    /**
     * @param Util\SelectedStepsFactory $factory
     * @param Io $io
     */
    private function maybePrintFactoryError(Util\SelectedStepsFactory $factory, Io $io)
    {
        $error = $factory->lastError();
        if ($error) {
            $text = Io::ensureLength($error);
            $io->writeErrorLine('');
            array_walk($text, [$io, 'writeErrorLine']);
            $io->writeErrorLine('');
        }
    }
}
