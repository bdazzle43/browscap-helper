<?php
/**
 * This file is part of the browscap-helper package.
 *
 * Copyright (c) 2015-2017, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Command;

use BrowscapHelper\Helper\Browser;
use BrowscapHelper\Helper\Device;
use BrowscapHelper\Helper\Engine;
use BrowscapHelper\Helper\Platform;
use BrowscapHelper\Helper\TargetDirectory;
use BrowscapHelper\Source\DetectorSource;
use BrowscapHelper\Source\DirectorySource;
use BrowserDetector\Detector;
use League\Flysystem\UnreadableFileException;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use UaResult\Result\Result;
use Wurfl\Request\GenericRequestFactory;

/**
 * Class CreateTestsCommand
 *
 * @category   Browscap Helper
 *
 * @author     Thomas Müller <mimmi20@live.de>
 */
class CreateTestsCommand extends Command
{
    /**
     * @var string
     */
    private $sourcesDirectory = '';

    /**
     * @var \Monolog\Logger
     */
    private $logger = null;

    /**
     * @var \Psr\Cache\CacheItemPoolInterface
     */
    private $cache = null;

    /**
     * @var \BrowserDetector\Detector
     */
    private $detector = null;

    /**
     * @param \Monolog\Logger                   $logger
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     * @param \BrowserDetector\Detector         $detector
     * @param string                            $sourcesDirectory
     */
    public function __construct(Logger $logger, CacheItemPoolInterface $cache, Detector $detector, $sourcesDirectory)
    {
        $this->sourcesDirectory = $sourcesDirectory;
        $this->logger           = $logger;
        $this->cache            = $cache;
        $this->detector         = $detector;

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
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @see    setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $consoleLogger = new ConsoleLogger($output);
        $this->logger->pushHandler(new PsrHandler($consoleLogger));

        $output->writeln('reading already existing tests ...');
        $checks = [];

        foreach ((new DetectorSource($this->logger, $this->cache))->getUserAgents() as $useragent) {
            if (isset($checks[$useragent])) {
                continue;
            }

            $checks[$useragent] = $useragent;
        }

        try {
            $number = (new TargetDirectory())->getNextTest($output);
        } catch (UnreadableFileException $e) {
            $this->logger->critical($e);
            $output->writeln($e->getMessage());

            return;
        }

        $output->writeln('next test: ' . $number);
        $output->writeln('reading new files ...');

        $sourcesDirectory = $input->getOption('resources');
        $outputBrowscap   = "<?php\n\nreturn [\n";
        $outputDetector   = [];
        $counter          = 0;
        $issue            = 'test-' . sprintf('%1$08d', $number);

        foreach ((new DirectorySource($this->logger, $sourcesDirectory))->getUserAgents() as $useragent) {
            $useragent = trim($useragent);

            if (isset($checks[$useragent])) {
                continue;
            }

            $output->writeln('    parsing ua ' . $useragent);

            $this->parseLine($useragent, $counter, $outputBrowscap, $outputDetector, $number, $output);
            $checks[$useragent] = $issue;
            ++$counter;
        }

        $outputBrowscap .= "];\n";

        file_put_contents('results/issue-' . sprintf('%1$05d', $number) . '.php', $outputBrowscap);

        $chunks          = array_chunk($outputDetector, 100, true);
        $targetDirectory = 'vendor/mimmi20/browser-detector-tests/tests/issues/' . sprintf('%1$05d', $number) . '/';
        if (!file_exists($targetDirectory)) {
            mkdir($targetDirectory);
        }

        foreach ($chunks as $chunkId => $chunk) {
            if (!count($chunk)) {
                continue;
            }

            file_put_contents(
                $targetDirectory . 'test-' . sprintf('%1$05d', $number) . '-' . sprintf('%1$05d', (int) $chunkId) . '.json',
                json_encode(
                    $chunk,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT
                ) . PHP_EOL
            );
        }

        $output->writeln('');
        $output->writeln($counter . ' tests exported');
    }

    /**
     * @param string          $ua
     * @param int             $counter
     * @param string          &$outputBrowscap
     * @param array           &$outputDetector
     * @param int             $testNumber
     * @param OutputInterface $output
     */
    private function parseLine($ua, $counter, &$outputBrowscap, array &$outputDetector, $testNumber, OutputInterface $output)
    {
        $platformCodename = 'unknown';

        $output->writeln('      detecting platform ...');

        list(
            $platformNameBrowscap,
            $platformMakerBrowscap,
            $platformDescriptionBrowscap,
            $platformVersionBrowscap,
            $win64,
            $win32,
            $win16,
            $standard,
            $platformBits,
            $platform) = (new Platform())->detect($this->cache, $ua, $this->detector, $platformCodename);

        $output->writeln('      detecting device ...');

        $deviceCode = 'unknown';

        /** @var \UaResult\Device\DeviceInterface $device */
        list($device) = (new Device())->detect($this->cache, $ua, $platform, $this->detector, $deviceCode);

        $engineCode = 'unknown';

        /** @var \UaResult\Engine\EngineInterface $engine */
        list(
            $engine,
            $applets,
            $activex) = (new Engine())->detect($this->cache, $ua, $this->detector, $engineCode);

        $output->writeln('      detecting browser ...');

        $browserNameDetector = 'unknown';

        /** @var \UaResult\Browser\Browser $browser */
        list(
            $browser,
            $lite) = (new Browser())->detect($this->cache, $ua, $this->detector, $browserNameDetector);

        $v          = explode('.', $browser->getVersion()->getVersion(), 2);
        $maxVersion = $v[0];
        $minVersion = (isset($v[1]) ? $v[1] : '0');

        $formatedIssue   = sprintf('%1$05d', (int) $testNumber);
        $formatedCounter = sprintf('%1$05d', (int) $counter);

        $output->writeln('      writing browscap data ...');

        $outputBrowscap .= "    'issue-$formatedIssue-$formatedCounter' => [
        'ua' => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $ua) . "',
        'properties' => [
            'Comment' => 'Default Browser',
            'Browser' => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $browser->getName()) . "',
            'Browser_Type' => '" . $browser->getType()->getName() . "',
            'Browser_Bits' => '" . $browser->getBits() . "',
            'Browser_Maker' => '" . $browser->getManufacturer()->getName() . "',
            'Browser_Modus' => '" . $browser->getModus() . "',
            'Version' => '" . $browser->getVersion()->getVersion() . "',
            'MajorVer' => '" . $maxVersion . "',
            'MinorVer' => '" . $minVersion . "',
            'Platform' => '" . $platformNameBrowscap . "',
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
            'isMobileDevice' => " . ($device->getType()->isMobile() ? 'true' : 'false') . ",
            'isTablet' => " . ($device->getType()->isTablet() ? 'true' : 'false') . ",
            'isSyndicationReader' => false,
            'Crawler' => " . ($browser->getType()->isBot() ? 'true' : 'false') . ",
            'isFake' => false,
            'isAnonymized' => false,
            'isModified' => false,
            'CssVersion' => '0',
            'AolVersion' => '0',
            'Device_Name' => '" . $device->getMarketingName() . "',
            'Device_Maker' => '" . $device->getManufacturer()->getName() . "',
            'Device_Type' => '" . $device->getType()->getName() . "',
            'Device_Pointing_Method' => '" . $device->getPointingMethod() . "',
            'Device_Code_Name' => '" . $device->getDeviceName() . "',
            'Device_Brand_Name' => '" . $device->getBrand()->getBrandName() . "',
            'RenderingEngine_Name' => '" . $engine->getName() . "',
            'RenderingEngine_Version' => 'unknown',
            'RenderingEngine_Maker' => '" . $engine->getManufacturer()->getName() . "',
        ],
        'lite' => " . ($lite ? 'true' : 'false') . ",
        'standard' => " . ($standard ? 'true' : 'false') . ",
    ],\n";

        $output->writeln('      detecting test name ...');

        $formatedIssue   = sprintf('%1$08d', (int) $testNumber);
        $formatedCounter = sprintf('%1$08d', (int) $counter);

        $output->writeln('      detecting request ...');

        $request = (new GenericRequestFactory())->createRequestForUserAgent($ua);

        $result = new Result($request, $device, $platform, $browser, $engine);

        $outputDetector['test-' . $formatedIssue . '-' . $formatedCounter] = [
            'ua'     => $ua,
            'result' => $result->toArray(false),
        ];
    }
}
