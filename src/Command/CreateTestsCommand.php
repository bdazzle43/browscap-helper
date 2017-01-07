<?php
/**
 * Copyright (c) 1998-2014 Browser Capabilities Project
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * Refer to the LICENSE file distributed with this package.
 *
 * @category   Browscap
 * @copyright  1998-2014 Browser Capabilities Project
 * @license    MIT
 */

namespace BrowscapHelper\Command;

use BrowscapHelper\Helper\Browser;
use BrowscapHelper\Helper\Device;
use BrowscapHelper\Helper\Engine;
use BrowscapHelper\Helper\Platform;
use BrowscapHelper\Helper\TargetDirectory;
use BrowscapHelper\Reader\TxtFileReader;
use BrowscapHelper\Reader\YamlFileReader;
use BrowserDetector\BrowserDetector;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Monolog\Handler;
use Monolog\Logger;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use UaResult\Os\Os;

/**
 * Class DiffCommand
 *
 * @category   Browscap
 * @author     James Titcumb <james@asgrim.com>
 */
class CreateTestsCommand extends Command
{
    /**
     * @var string
     */
    private $sourcesDirectory = '';

    /**
     * @param string $sourcesDirectory
     *
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct($sourcesDirectory)
    {
        $this->sourcesDirectory = $sourcesDirectory;

        parent::__construct();
    }

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this
            ->setName('create-tests')
            ->setDescription('Creates tests from the apache log files')
            ->addOption(
                'resources',
                null,
                InputOption::VALUE_REQUIRED,
                'Where the resource files are located',
                $this->sourcesDirectory
            );
    }

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @throws \LogicException When this abstract method is not implemented
     * @return null|int        null or 0 if everything went fine, or an error code
     *
     * @see    setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /*******************************************************************************
         * loading files
         ******************************************************************************/

        $output->writeln('init logger ...');
        $logger = new Logger('browser-detector-helper');
        $logger->pushHandler(new Handler\NullHandler());
        $logger->pushHandler(new Handler\StreamHandler('error.log', Logger::ERROR));

        $output->writeln('init cache ...');
        $adapter  = new Local(__DIR__ . '/../../cache/');
        $cache    = new FilesystemCachePool(new Filesystem($adapter));

        $output->writeln('init detector ...');
        $detector = new BrowserDetector($cache, $logger);

        //@todo: read from test sources
        //@todo: only write uas which are not written yet
        $output->writeln('reading files from browscap ...');

        $browscapIssueDirectory = 'vendor/browscap/browscap/tests/fixtures/issues/';
        $browscapIssueIterator  = new \RecursiveDirectoryIterator($browscapIssueDirectory);
        $checks                 = [];

        foreach (new \RecursiveIteratorIterator($browscapIssueIterator) as $file) {
            /** @var $file \SplFileInfo */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $tests = require_once $file->getPathname();

            foreach ($tests as $key => $test) {
                if (isset($data[$key])) {
                    continue;
                }

                if (isset($checks[$test['ua']])) {
                    continue;
                }

                $data[$key]          = $test;
                $checks[$test['ua']] = $key;
            }
        }

        try {
            $number = (new TargetDirectory())->getNextTest($output);
        } catch (\League\Flysystem\UnreadableFileException $e) {
            $logger->critical($e);
            $output->writeln($e->getMessage());

            return;
        }

        $output->writeln('reading new files ...');

        $sourcesDirectory = $input->getOption('resources');
        $sourcesIterator  = new \RecursiveDirectoryIterator($sourcesDirectory);
        $counter          = 0;

        foreach (new \RecursiveIteratorIterator($sourcesIterator) as $file) {
            /** @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $output->writeln('file ' . $file->getBasename());
            $output->writeln('    checking ...');

            $fileContents = [];

            switch ($file->getExtension()) {
                case 'txt':
                    $reader = new TxtFileReader();
                    $reader->setLocalFile($file->getPathname());

                    $fileContents = $reader->getAgents();
                    break;
                case 'yaml':
                    $reader = new YamlFileReader();
                    $reader->setLocalFile($file->getPathname());

                    $fileContents = $reader->getAgents();
                    break;
                default:
                    continue;
            }

            if (empty($fileContents)) {
                continue;
            }

            $output->writeln('    parsing ...');

            $chunks = array_chunk($fileContents, 20000, true);

            if (count($chunks) > 1) {
                $output->writeln('    found too many user agnets, will create ' . count($chunks) . ' files ...');
            }

            foreach ($chunks as $chunkId => $chunk) {
                $nextTest = $number + $chunkId;

                if (count($chunks) > 1) {
                    $output->writeln('    processing test ' . $nextTest . ' ...');
                }

                $counter += $this->parseFile(
                    $output,
                    $cache,
                    $chunk,
                    $number + $chunkId,
                    $checks,
                    $detector
                );
            }
        }

        $output->writeln('');
        $output->writeln('Es wurden ' . $counter . ' Tests exportiert');
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param \Psr\Cache\CacheItemPoolInterface                 $cache
     * @param array                                             $fileContents
     * @param int                                               $testNumber
     * @param array                                             &$checks
     * @param \BrowserDetector\BrowserDetector                  $detector
     *
     * @return int
     */
    private function parseFile(
        OutputInterface $output,
        CacheItemPoolInterface $cache,
        array $fileContents = [],
        $testNumber = 0,
        array &$checks = [],
        BrowserDetector $detector = null
    ) {
        $outputBrowscap = "<?php\n\nreturn [\n";
        $outputDetector = [];
        $counter        = 0;
        $i              = 0;
        $issue          = 'test-' . sprintf('%1$08d', $testNumber);

        foreach (array_keys($fileContents) as $ua) {
            $ua = trim($ua);

            if (isset($checks[$ua])) {
                $output->writeln('        useragent ' . $i . ' was already checked, found in ' . $checks[$ua]);
                continue;
            }

            $this->parseLine($cache, $ua, $i, $checks, $counter, $outputBrowscap, $outputDetector, $testNumber, $detector);
            $checks[$ua] = $issue;
            ++$i;
        }

        $outputBrowscap .= "];\n";

        file_put_contents('results/issue-' . $issue . '.php', $outputBrowscap);

        $chunks          = array_chunk($outputDetector, 100, true);
        $targetDirectory = 'vendor/mimmi20/browser-detector-tests/tests/issues/' . sprintf('%1$05d', $testNumber) . '/';
        if (!file_exists($targetDirectory)) {
            mkdir($targetDirectory);
        }

        foreach ($chunks as $chunkId => $chunk) {
            if (!count($chunk)) {
                $output->writeln('    skip empty chunk ' . $chunkId);
                continue;
            }

            $chunkNumber = sprintf('%1$08d', (int) $chunkId);

            file_put_contents(
                $targetDirectory . $issue . '-' . $chunkNumber . '.json',
                json_encode(
                    $chunk,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . PHP_EOL
            );
        }

        return $counter;
    }

    /**
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     * @param string                            $ua
     * @param int                               $i
     * @param array                             &$checks
     * @param int                               &$counter
     * @param string                            &$outputBrowscap
     * @param array                             &$outputDetector
     * @param int                               $testNumber
     * @param \BrowserDetector\BrowserDetector  $detector
     */
    private function parseLine(CacheItemPoolInterface $cache, $ua, $i, array &$checks, &$counter, &$outputBrowscap, array &$outputDetector, $testNumber, BrowserDetector $detector)
    {
        $engineVersion = 'unknown';

        list(
            $browserNameBrowscap,
            $browserType,
            $browserBits,
            $browserMaker,
            $browserModus,
            $browserVersion,
            $browserNameDetector,
            $lite,
            $crawler) = (new Browser())->detect($ua);

        $platformCodename = 'unknown';

        list(
            $platformNameBrowscap,
            $platformMakerBrowscap,
            $platformDescriptionBrowscap,
            $platformVersionBrowscap,
            $win64,
            $win32,
            $win16,
            $platformCodenameDetector,
            $platformMarketingnameDetector,
            $platformMakerNameDetector,
            $platformMakerBrandnameDetector,
            $platformVersionDetector,
            $standard,
            $platformBits) = (new Platform())->detect($cache, $ua, $detector, $platformCodename);

        $platform   = new Os('unknown', 'unknown', 'unknown', 'unknown');
        $deviceCode = 'unknown';

        /** @var $deviceType \UaDeviceType\TypeInterface */
        list(
            $deviceName,
            $deviceMaker,
            $deviceType,
            $pointingMethod,
            $deviceCodename,
            $deviceBrandname,
            $mobileDevice,
            $isTablet,
            $deviceOrientation) = (new Device())->detect($cache, $ua, $platform, $detector, $deviceCode);

        list(
            $engineName,
            $engineMaker,
            $applets,
            $activex) = (new Engine())->detect($ua);

        $v          = explode('.', $browserVersion, 2);
        $maxVersion = $v[0];
        $minVersion = (isset($v[1]) ? $v[1] : '0');

        $formatedIssue   = sprintf('%1$05d', (int) $testNumber);
        $formatedCounter = sprintf('%1$05d', (int) $i);

        $outputBrowscap .= "    'issue-$formatedIssue-$formatedCounter' => [
        'ua' => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $ua) . "',
        'properties' => [
            'Comment' => 'Default Browser',
            'Browser' => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $browserNameBrowscap) . "',
            'Browser_Type' => '$browserType',
            'Browser_Bits' => '$browserBits',
            'Browser_Maker' => '$browserMaker',
            'Browser_Modus' => '$browserModus',
            'Version' => '$browserVersion',
            'MajorVer' => '$maxVersion',
            'MinorVer' => '$minVersion',
            'Platform' => '$platformNameBrowscap',
            'Platform_Version' => '$platformVersionBrowscap',
            'Platform_Description' => '$platformDescriptionBrowscap',
            'Platform_Bits' => '$platformBits',
            'Platform_Maker' => '$platformMakerBrowscap',
            'Alpha' => false,
            'Beta' => false,
            'Win16' => " . ($win16 ? 'true' : 'false') . ",
            'Win32' => " . ($win32 ? 'true' : 'false') . ",
            'Win64' => " . ($win64 ? 'true' : 'false') . ",
            'Frames' => true,
            'IFrames' => true,
            'Tables' => true,
            'Cookies' => true,
            'BackgroundSounds' => " . ($activex ? 'true' : 'false') . ",
            'JavaScript' => true,
            'VBScript' => " . ($activex ? 'true' : 'false') . ",
            'JavaApplets' => " . ($applets ? 'true' : 'false') . ",
            'ActiveXControls' => " . ($activex ? 'true' : 'false') . ",
            'isMobileDevice' => " . ($mobileDevice ? 'true' : 'false') . ",
            'isTablet' => " . ($isTablet ? 'true' : 'false') . ",
            'isSyndicationReader' => false,
            'Crawler' => " . ($crawler ? 'true' : 'false') . ",
            'isFake' => false,
            'isAnonymized' => false,
            'isModified' => false,
            'CssVersion' => '0',
            'AolVersion' => '0',
            'Device_Name' => '" . $deviceName . "',
            'Device_Maker' => '" . $deviceMaker . "',
            'Device_Type' => '" . $deviceType->getName() . "',
            'Device_Pointing_Method' => '" . $pointingMethod . "',
            'Device_Code_Name' => '" . $deviceCodename . "',
            'Device_Brand_Name' => '" . $deviceBrandname . "',
            'RenderingEngine_Name' => '$engineName',
            'RenderingEngine_Version' => '$engineVersion',
            'RenderingEngine_Maker' => '$engineMaker',
        ],
        'lite' => " . ($lite ? 'true' : 'false') . ",
        'standard' => " . ($standard ? 'true' : 'false') . ",
    ],\n";

        $formatedIssue   = sprintf('%1$08d', (int) $testNumber);
        $formatedCounter = sprintf('%1$08d', (int) $counter);

        $outputDetector['test-' . $formatedIssue . '-' . $formatedCounter] = [
            'ua'         => $ua,
            'properties' => [
                'Browser_Name'            => $browserNameDetector,
                'Browser_Type'            => $browserType,
                'Browser_Bits'            => $browserBits,
                'Browser_Maker'           => $browserMaker,
                'Browser_Modus'           => $browserModus,
                'Browser_Version'         => $browserVersion,
                'Platform_Codename'       => $platformCodenameDetector,
                'Platform_Marketingname'  => $platformMarketingnameDetector,
                'Platform_Version'        => $platformVersionDetector,
                'Platform_Bits'           => $platformBits,
                'Platform_Maker'          => $platformMakerNameDetector,
                'Platform_Brand_Name'     => $platformMakerBrandnameDetector,
                'Device_Name'             => $deviceName,
                'Device_Maker'            => $deviceMaker,
                'Device_Type'             => get_class($deviceType),
                'Device_Pointing_Method'  => $pointingMethod,
                'Device_Dual_Orientation' => $deviceOrientation,
                'Device_Code_Name'        => $deviceCodename,
                'Device_Brand_Name'       => $deviceBrandname,
                'RenderingEngine_Name'    => $engineName,
                'RenderingEngine_Version' => $engineVersion,
                'RenderingEngine_Maker'   => $engineMaker,
            ],
        ];

        ++$counter;

        $checks[$ua] = $i;

        return;
    }
}
