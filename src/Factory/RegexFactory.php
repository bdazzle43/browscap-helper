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
namespace BrowscapHelper\Factory;

use BrowscapHelper\Loader\RegexLoader;
use BrowserDetector\Factory;
use BrowserDetector\Loader\BrowserLoader;
use BrowserDetector\Loader\DeviceLoader;
use BrowserDetector\Loader\EngineLoader;
use BrowserDetector\Loader\NotFoundException;
use BrowserDetector\Loader\PlatformLoader;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Stringy\Stringy;

/**
 * detection class using regexes
 *
 * @category  BrowserDetector
 *
 * @author    Thomas Mueller <mimmi20@live.de>
 * @copyright 2012-2017 Thomas Mueller
 * @license   http://www.opensource.org/licenses/MIT MIT License
 */
class RegexFactory implements Factory\FactoryInterface
{
    /**
     * @var \Psr\Cache\CacheItemPoolInterface|null
     */
    private $cache = null;

    /**
     * @var array|null
     */
    private $match = null;

    /**
     * @var string|null
     */
    private $useragent = null;

    /**
     * an logger instance
     *
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * @var bool
     */
    private $runDetection = false;

    /**
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     * @param \Psr\Log\LoggerInterface          $logger
     */
    public function __construct(CacheItemPoolInterface $cache, LoggerInterface $logger)
    {
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * Gets the information about the rendering engine by User Agent
     *
     * @param string $useragent
     *
     * @throws \BrowserDetector\Loader\NotFoundException
     * @throws \InvalidArgumentException
     * @throws \BrowscapHelper\Factory\Regex\NoMatchException
     */
    public function detect($useragent)
    {
        $regexes = (new RegexLoader($this->cache, $this->logger))->getRegexes();

        $this->match     = null;
        $this->useragent = $useragent;

        if (!is_array($regexes)) {
            throw new \InvalidArgumentException('no regexes are defined');
        }

        foreach ($regexes as $regex) {
            $matches = [];

            //$this->logger->error($regex);
            if (preg_match($regex, $useragent, $matches)) {
                $this->match = $matches;

                $this->runDetection = true;

                return;
            }
        }

        $this->runDetection = true;
        throw new Regex\NoMatchException('no regex did match');
    }

    /**
     * @throws \BrowserDetector\Loader\NotFoundException
     *
     * @return array
     */
    public function getDevice()
    {
        if (null === $this->useragent) {
            throw new \InvalidArgumentException('no useragent was set');
        }

        if (!is_array($this->match) && $this->runDetection) {
            throw new \InvalidArgumentException('device not found via regexes');
        }

        if (!is_array($this->match)) {
            throw new \InvalidArgumentException('please call the detect function before trying to get the result');
        }

        if (!array_key_exists('devicecode', $this->match) || '' === $this->match['devicecode']) {
            throw new Regex\NoMatchException('device not detected via regexes');
        }

        $deviceCode   = mb_strtolower($this->match['devicecode']);
        $deviceLoader = new DeviceLoader($this->cache);

        $s = new Stringy($this->useragent);

        if ('windows' === $deviceCode) {
            return $deviceLoader->load('windows desktop', $this->useragent);
        } elseif ('macintosh' === $deviceCode) {
            return $deviceLoader->load('macintosh', $this->useragent);
        } elseif ('cfnetwork' === $deviceCode) {
            try {
                return (new Factory\Device\DarwinFactory($deviceLoader))->detect($this->useragent, $s);
            } catch (NotFoundException $e) {
                $this->logger->warning($e);
                throw $e;
            }
        } elseif ('dalvik' === $deviceCode || 'android' === $deviceCode) {
            try {
                return (new Factory\Device\MobileFactory($deviceLoader))->detect($this->useragent, $s);
            } catch (NotFoundException $e) {
                $this->logger->warning($e);
                throw $e;
            }
        } elseif ('linux' === $deviceCode || 'cros' === $deviceCode) {
            return $deviceLoader->load('linux desktop', $this->useragent);
        } elseif ('touch' === $deviceCode
            && array_key_exists('osname', $this->match)
            && 'bb10' === mb_strtolower($this->match['osname'])
        ) {
            return $deviceLoader->load('z10', $this->useragent);
        }

        if (array_key_exists('manufacturercode', $this->match)) {
            $manufacturercode = mb_strtolower($this->match['manufacturercode']);
        } else {
            $manufacturercode = '';
        }

        if ($deviceLoader->has($manufacturercode . ' ' . $deviceCode)) {
            /** @var \UaResult\Device\DeviceInterface $device */
            list($device, $platform) = $deviceLoader->load($manufacturercode . ' ' . $deviceCode, $this->useragent);

            if (!in_array($device->getDeviceName(), ['unknown', null])) {
                $this->logger->debug('device detected via manufacturercode and devicecode');

                return [$device, $platform];
            }
        }

        if ($deviceLoader->has($deviceCode)) {
            /** @var \UaResult\Device\DeviceInterface $device */
            list($device, $platform) = $deviceLoader->load($deviceCode, $this->useragent);

            if (!in_array($device->getDeviceName(), ['unknown', null])) {
                $this->logger->debug('device detected via devicecode');

                return [$device, $platform];
            }
        }

        if ($manufacturercode) {
            $className = '\\BrowserDetector\\Factory\\Device\\Mobile\\' . ucfirst($manufacturercode) . 'Factory';

            if (class_exists($className)) {
                $this->logger->debug('device detected via manufacturer');
                /** @var \BrowserDetector\Factory\FactoryInterface $factory */
                $factory = new $className($deviceLoader);

                try {
                    return $factory->detect($this->useragent, $s);
                } catch (NotFoundException $e) {
                    $this->logger->warning($e);
                    throw $e;
                }
            } else {
                $this->logger->warning('factory "' . $className . '" not found');
            }

            $this->logger->info('device manufacturer class was not found');
        }

        if (array_key_exists('devicetype', $this->match)) {
            if ('wpdesktop' === mb_strtolower($this->match['devicetype']) || 'xblwp7' === mb_strtolower($this->match['devicetype'])) {
                $factory = new Factory\Device\MobileFactory($deviceLoader);

                try {
                    return $factory->detect($this->useragent, $s);
                } catch (NotFoundException $e) {
                    $this->logger->warning($e);
                    throw $e;
                }
            } else {
                $className = '\\BrowserDetector\\Factory\\Device\\' . ucfirst(mb_strtolower($this->match['devicetype'])) . 'Factory';

                if (class_exists($className)) {
                    $this->logger->debug('device detected via device type (mobile or tv)');
                    /** @var \BrowserDetector\Factory\FactoryInterface $factory */
                    $factory = new $className($deviceLoader);

                    try {
                        return $factory->detect($this->useragent, $s);
                    } catch (NotFoundException $e) {
                        $this->logger->warning($e);
                        throw $e;
                    }
                } else {
                    $this->logger->warning('factory "' . $className . '" not found');
                }

                $this->logger->info('device type class was not found');
            }
        }

        throw new NotFoundException('device not found via regexes');
    }

    /**
     * @return \UaResult\Os\OsInterface
     */
    public function getPlatform()
    {
        if (null === $this->useragent) {
            throw new \InvalidArgumentException('no useragent was set');
        }

        if (!is_array($this->match) && $this->runDetection) {
            throw new NotFoundException('platform not found via regexes');
        }

        if (!is_array($this->match)) {
            throw new \InvalidArgumentException('please call the detect function before trying to get the result');
        }

        $platformLoader = new PlatformLoader($this->cache);

        if (!array_key_exists('osname', $this->match)
            && array_key_exists('manufacturercode', $this->match)
            && 'blackberry' === mb_strtolower($this->match['manufacturercode'])
        ) {
            $this->logger->debug('platform forced to rim os');

            return $platformLoader->load('rim os', $this->useragent);
        }

        if (!array_key_exists('osname', $this->match) || '' === $this->match['osname']) {
            throw new Regex\NoMatchException('platform not detected via regexes');
        }

        $platformCode = mb_strtolower($this->match['osname']);

        $s = new Stringy($this->useragent);

        if ('darwin' === $platformCode) {
            $darwinFactory = new Factory\Platform\DarwinFactory($platformLoader);

            return $darwinFactory->detect($this->useragent, $s);
        }

        $s = new Stringy($this->useragent);

        if ('linux' === $platformCode && array_key_exists('devicecode', $this->match)) {
            // Android Desktop Mode
            $platformCode = 'android';
        } elseif ('adr' === $platformCode) {
            // Android Desktop Mode with UCBrowser
            $platformCode = 'android';
        } elseif ('linux' === $platformCode && $s->containsAll(['opera mini', 'ucbrowser'], false)) {
            // Android Desktop Mode with UCBrowser
            $platformCode = 'android';
        } elseif ('linux' === $platformCode) {
            $linuxFactory = new Factory\Platform\LinuxFactory($platformLoader);

            return $linuxFactory->detect($this->useragent, $s);
        } elseif ('bb10' === $platformCode || 'blackberry' === $platformCode) {
            // Rim OS
            $platformCode = 'rim os';
        } elseif ('cros' === $platformCode) {
            $platformCode = 'chromeos';
        }

        if (false !== mb_strpos($platformCode, 'windows nt') && array_key_exists('devicetype', $this->match)) {
            // Windows Phone Desktop Mode
            $platformCode = 'windows phone';
        }

        if ($platformLoader->has($platformCode)) {
            $platform = $platformLoader->load($platformCode, $this->useragent);

            if (!in_array($platform->getName(), ['unknown', null])) {
                return $platform;
            }

            $this->logger->info('platform with code "' . $platformCode . '" not found via regexes');
        }

        throw new NotFoundException('platform not found via regexes');
    }

    /**
     * @return array
     */
    public function getBrowser()
    {
        if (null === $this->useragent) {
            throw new \InvalidArgumentException('no useragent was set');
        }

        if (!is_array($this->match) && $this->runDetection) {
            throw new NotFoundException('browser not found via regexes');
        }

        if (!is_array($this->match)) {
            throw new \InvalidArgumentException('please call the detect function before trying to get the result');
        }

        if (!array_key_exists('browsername', $this->match) || '' === $this->match['browsername']) {
            throw new Regex\NoMatchException('browser not detected via regexes');
        }

        $browserCode   = mb_strtolower($this->match['browsername']);
        $browserLoader = new BrowserLoader($this->cache);

        switch ($browserCode) {
            case 'opr':
                $browserCode = 'opera';
                break;
            case 'msie':
                $browserCode = 'internet explorer';
                break;
            case 'ucweb':
            case 'ubrowser':
                $browserCode = 'ucbrowser';
                break;
            case 'crmo':
                $browserCode = 'chrome';
                break;
            case 'granparadiso':
                $browserCode = 'firefox';
                break;
            default:
                // do nothing here
        }

        if ('safari' === $browserCode) {
            if (array_key_exists('osname', $this->match)) {
                $osname = mb_strtolower($this->match['osname']);

                if ('android' === $osname || 'linux' === $osname) {
                    return $browserLoader->load('android webkit', $this->useragent);
                }

                if ('tizen' === $osname) {
                    return $browserLoader->load('samsungbrowser', $this->useragent);
                }

                if ('blackberry' === $osname) {
                    return $browserLoader->load('blackberry', $this->useragent);
                }

                if ('symbian' === $osname || 'symbianos' === $osname) {
                    return $browserLoader->load('android webkit', $this->useragent);
                }
            }

            if (array_key_exists('manufacturercode', $this->match)) {
                $devicemaker = mb_strtolower($this->match['manufacturercode']);

                if ('nokia' === $devicemaker) {
                    return $browserLoader->load('nokiabrowser', $this->useragent);
                }
            }
        }

        if ($browserLoader->has($browserCode)) {
            /** @var \UaResult\Browser\BrowserInterface $browser */
            list($browser) = $browserLoader->load($browserCode, $this->useragent);

            if (!in_array($browser->getName(), ['unknown', null])) {
                return [$browser];
            }

            $this->logger->info('browser with code "' . $browserCode . '" not found via regexes');
        }

        throw new NotFoundException('browser not found via regexes');
    }

    /**
     * @return \UaResult\Engine\EngineInterface
     */
    public function getEngine()
    {
        if (null === $this->useragent) {
            throw new \InvalidArgumentException('no useragent was set');
        }

        if (!is_array($this->match) && $this->runDetection) {
            throw new NotFoundException('engine not found via regexes');
        }

        if (!is_array($this->match)) {
            throw new \InvalidArgumentException('please call the detect function before trying to get the result');
        }

        if (!array_key_exists('enginename', $this->match) || '' === $this->match['enginename']) {
            throw new Regex\NoMatchException('engine not detected via regexes');
        }

        $engineCode   = mb_strtolower($this->match['enginename']);
        $engineLoader = new EngineLoader($this->cache);

        if ('cfnetwork' === $engineCode) {
            return $engineLoader->load('webkit', $this->useragent);
        }

        if (in_array($engineCode, ['applewebkit', 'webkit'])) {
            if (array_key_exists('chromeversion', $this->match)) {
                $chromeversion = (int) $this->match['chromeversion'];
            } else {
                $chromeversion = 0;
            }

            if ($chromeversion >= 28) {
                $engineCode = 'blink';
            } else {
                $engineCode = 'webkit';
            }
        }

        if ($engineLoader->has($engineCode)) {
            $engine = $engineLoader->load($engineCode, $this->useragent);

            if (!in_array($engine->getName(), ['unknown', null])) {
                return $engine;
            }

            $this->logger->info('engine with code "' . $engineCode . '" not found via regexes');
        }

        throw new NotFoundException('engine not found via regexes');
    }
}