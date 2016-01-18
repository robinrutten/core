<?php
namespace Nodes\Support;

use Illuminate\Console\Command as IlluminateConsoleCommand;
use Nodes\Exceptions\InstallPackageException;

/**
 * Class InstallPackage
 *
 * @package Nodes\Support
 */
class InstallPackage
{
    /**
     * Vendor name
     *
     * @var string
     */
    protected $vendor = 'nodes';

    /**
     * Package name
     *
     * @var string
     */
    protected $packageName;

    /**
     * Path to package
     *
     * @var string
     */
    protected $packagePath;

    /**
     * Service provider class name
     *
     * @var string
     */
    protected $serviceProvider;

    /**
     * Path of service provider file
     *
     * @var string
     */
    protected $serviceProviderFilePath;

    /**
     * Composer's class map
     *
     * @var array
     */
    protected $composerClassMap;

    /**
     * Installer instance
     *
     * @var \Illuminate\Console\Command
     */
    protected $installer;

    /**
     * InstallPackage constructor
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     */
    public function __construct()
    {
        // Composers class map
        $this->composerClassMap = require(base_path('vendor/composer/autoload_classmap.php'));
    }

    /**
     * Prepare package by locating package path
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access protected
     * @return $this
     * @throws \Nodes\Exceptions\InstallPackageException
     */
    protected function preparePackage()
    {
        // Validate required package information
        if (empty($this->vendor) || empty($this->packageName)) {
            throw new InstallPackageException(sprintf('Vendor [%s] or Package Name [%s] is not set.', $this->vendor, $this->packageName), 400);
        }

        // Generate path to package
        // $vendorPath = base_path(sprintf('vendor/%s/%s', $name[0], $name[1]));
        $this->packagePath = $packagePath = base_path(sprintf('%s/%s', $this->vendor, $this->packageName));

        // Check if package exists in the vendor folder
        if (!file_exists($packagePath)) {
            throw new InstallPackageException(sprintf('[%s] was not be found in the vendor folder [%s].', $this->packageName, base_path('vendor/')), 400);
        }

        return $this;
    }

    /**
     * Prepare package to work on/with the service provider
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access protected
     * @param  string $serviceProviderFilename
     * @return $this
     * @throws \Nodes\Exceptions\InstallPackageException
     */
    protected function prepareServiceProvider($serviceProviderFilename)
    {
        // Generate path to service provider file
        $this->serviceProviderFilePath = $serviceProviderFilePath = sprintf('%s/src/%s', $this->packagePath, $serviceProviderFilename);
        if (!file_exists($serviceProviderFilePath)) {
            throw new InstallPackageException(sprintf('[%s] was not be found in the package folder [%s].', $serviceProviderFilename, $this->packagePath), 400);
        }

        $this->serviceProvider = $serviceProvider = array_search($serviceProviderFilePath, $this->composerClassMap);
        if (!$serviceProvider) {
            throw new InstallPackageException(sprintf('Service Provider [%s] for package [%s] was not found in Composers class map.', $serviceProvider, sprintf('%s/%s', $this->vendor, $this->packageName)), 400);
        }

        return $this;
    }

    /**
     * Check if package is installed
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @param  string $serviceProviderFilename
     * @return boolean
     * @throws \Nodes\Exceptions\InstallPackageException
     */
    public function isPackageInstalled($serviceProviderFilename = 'ServiceProvider.php')
    {
        // Prepare package and service provider
        $this->preparePackage()->prepareServiceProvider($serviceProviderFilename);

        // Load Laravel's "app config" into an array
        $config = file(config_path('app.php'));

        // Look for package service provider
        $checkIfServiceProviderIsAlreadyInstalled = array_keys(preg_grep(sprintf('|%s::class|', str_replace('\\', '\\\\', $this->serviceProvider)), $config));

        // If service provider is found,
        // we can conclude the package is already installed
        return !empty($checkIfServiceProviderIsAlreadyInstalled[0]) ? true : false;
    }

    /**
     * Add Nodes Service provider if missing
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @return boolean
     */
    public function addNodesServiceProvider()
    {
        // Load Laravel's "app config" into an array
        $config = file(config_path('app.php'));

        // Locate "Nodes Core Service Provider" position in providers array
        $locateNodesCoreProviderPosition = array_keys(preg_grep('|Nodes\\\\ServiceProvider::class|', $config));
        if (!empty($locateNodesCoreProviderPosition[0])) {
            return true;
        }

        $locateNodesCoreProviderArrayPosition = array_keys(preg_grep("|'providers' =>|", $config));
        for($i = $locateNodesCoreProviderArrayPosition[0]; $i < count($config); $i++) {
            // Trim current config line
            $value = trim($config[$i]);

            // If line is not the end of the "providers" array
            // continue onwards to next line
            if ($value != '],') {
                continue;
            }

            // Generate service provider content
            $serviceProviderSnippet  = '' . "\n";
            $serviceProviderSnippet .= str_repeat("\t", 2) . '/**' . "\n";
            $serviceProviderSnippet .= str_repeat("\t", 2) . ' * Nodes Service Providers' . "\n";
            $serviceProviderSnippet .= str_repeat("\t", 2) . ' */' . "\n";
            $serviceProviderSnippet .= str_repeat("\t", 2) . 'Nodes\ServiceProvider::class,' . "\n";

            // Add service provider snippet to config array
            array_splice($config, $i, 0, $serviceProviderSnippet);
            break;
        }

        // Update service provider config
        file_put_contents(config_path('app.php'), implode('', $config));

        return true;
    }

    /**
     * Install package's service provider
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @param  string  $serviceProviderFilename
     * @return string|boolean  Returns true if service provider is already installed
     * @throws \Nodes\Exceptions\InstallPackageException
     */
    public function installServiceProvider($serviceProviderFilename = 'ServiceProvider.php')
    {
        // Check if service provider is already installed
        if ($this->isPackageInstalled($serviceProviderFilename)) {
            return true;
        }

        // Make sure to load service provider
        // if it for some reason isn't already
        if (!class_exists($this->serviceProvider)) {
            require_once($this->serviceProviderFilePath);
        }

        // Load Laravel's app config into an array
        $config = file(config_path('app.php'));

        // Make sure package service provider isn't already installed
        $checkIfServiceProviderIsAlreadyInstalled = array_keys(preg_grep(sprintf('|%s::class|', str_replace('\\', '\\\\', $this->serviceProvider)), $config));
        if (!empty($checkIfServiceProviderIsAlreadyInstalled[0])) {
            return true;
        }

        // Locate position of Nodes (Core) service provider in config file
        $locateNodesCoreProviderPosition = array_keys(preg_grep('|Nodes\\\\ServiceProvider::class|', $config));
        if (empty($locateNodesCoreProviderPosition[0])) {
            // Nodes Core service provider is missing from config file.
            // We'll start by adding that and then try again.
            $this->addNodesServiceProvider();

            // Reload config file
            $config = file(config_path('app.php'));

            // Lets' try and locate the position again.
            $locateNodesCoreProviderPosition = array_keys(preg_grep('|Nodes\\\\ServiceProvider::class|', $config));
        }

        // Service Provider namespace
        $serviceProviderNamespace = explode('\\', sprintf('%s::class', $this->serviceProvider));

        // Add package service provider to Laravel's app config
        for($i = $locateNodesCoreProviderPosition[0]+1; $i < count($config); $i++) {
            // Get value of next item in providers array
            $value = trim($config[$i]);

            // If we're on a line where there's already a service provider,
            // we'll take the namespace and match it up against our own.
            // If our service provider doesn't fit here, we'll move on to next line.
            if (!empty($value) && $value != '],') {
                // Current item namespace
                $currentNamespace = explode('\\', $value);

                // Comparison state
                $shouldBeInsertedHere = false;

                // Determine if current item's namespace, comes before or after
                // our own service providers namespace, if sorted alphabetically
                foreach ($currentNamespace as $key => $namespacePart) {
                    // Compare current namespace parts
                    $comparison = strnatcmp($namespacePart, $serviceProviderNamespace[$key]);

                    // Namespace parts are identical
                    // move on to the next part.
                    if ($comparison == 0) {
                        continue;
                    }

                    // Difference found
                    $shouldBeInsertedHere = $comparison > 0 ? true : false;
                    break;
                }

                // After comparing we can conclude our service provider
                // should NOT be inserted at current line. And we're
                // therefore moving on to next line.
                if (!$shouldBeInsertedHere) {
                    continue;
                }
            }

            // Success!
            // Insert service provider at current line
            array_splice($config, $i, 0, [
                str_repeat("\t", 2) . sprintf('%s::class,', $this->serviceProvider) . "\n"
            ]);
            break;
        }

        // Update existing config
        file_put_contents(config_path('app.php'), implode('', $config));

        return $this->serviceProvider;
    }

    /**
     * Install facades belonging to package
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @param  array $facades
     * @return boolean|null
     */
    public function installFacades($facades)
    {
        // Validate received data
        if (empty($facades) || !is_array($facades)) {
            return false;
        }

        // Load Laravel's app config into an array
        $config = file(config_path('app.php'));

        // Locate beginning of $aliases array
        $locateAliasesArray = array_keys(preg_grep('|\'aliases\' => \[|', $config));
        if (empty($locateAliasesArray[0])) {
            return null;
        }

        foreach ($facades as $facadeName => $facadeNamespace) {
            // If facade is already installed,
            // we'll just skip it and move along.
            $checkIfFacadesIsAlreadyInstalled = array_keys(preg_grep(sprintf('|%s::class|', str_replace('\\', '\\\\', $facadeNamespace)), $config));
            if (!empty($checkIfFacadesIsAlreadyInstalled[0])) {
                continue;
            }

            for ($i = $locateAliasesArray[0]+1; $i < count($config); $i++) {
                // Get value of next line
                $value = trim($config[$i]);

                // If we're on an empty line, we'll continue to next one
                //
                // If we're on a line where there's already a facade,
                // we'll take the facade and match it up against our
                // current one. If our facade doesn't fit here,
                // we'll continue on to next line.
                if (empty($value)) {
                    continue;
                } elseif (!empty($value) && $value != '],') {
                    // Retrieve current facade name from line
                    $currentFacadeName = trim(explode('=>', $value)[0]);

                    // Remove single quotes wrapping facade name
                    $currentFacadeName = substr($currentFacadeName, 1, strlen($currentFacadeName)-1);

                    // Compare the two facades names.
                    // If current facade name comes before our own facade name
                    // we'll move on to next line
                    if (strnatcmp($currentFacadeName, $facadeName) != 1) {
                        continue;
                    }
                }

                // Success!
                // We're inserting our facade at the current line.
                array_splice($config, $i, 0, [
                    str_repeat("\t", 2) . sprintf('\'%s\' => %s::class,', $facadeName, $facadeNamespace) . "\n"
                ]);
                break;
            }
        }

        // Update existing config
        file_put_contents(config_path('app.php'), implode('', $config));

        return true;
    }

    /**
     * Set vendor name of package to install
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @param  string $vendorName
     * @return $this
     */
    public function setVendorName($vendorName)
    {
        $this->vendor = $vendorName;
        return $this;
    }

    /**
     * Set package name to install
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @param  string $packageName
     * @return $this
     */
    public function setPackageName($packageName)
    {
        $this->packageName = $packageName;
        return $this;
    }

    /**
     * Set installer instance
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @final
     * @access public
     * @param  \Illuminate\Console\Command $installer
     * @return $this
     *
    public function setInstaller(IlluminateConsoleCommand $installer)
    {
        $this->installer = $installer;
        return $this;
    }

    /**
     * Retrieve installer instance
     *
     * @author Morten Rugaard <moru@nodes.dk>
     *
     * @access public
     * @return \Illuminate\Console\Command
     *
    public function getInstaller()
    {
        return $this->installer;
    }
     */
}