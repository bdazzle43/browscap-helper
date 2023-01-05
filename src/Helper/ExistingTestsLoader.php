<?php
/**
 * This file is part of the browscap-helper package.
 *
 * Copyright (c) 2015-2022, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace BrowscapHelper\Helper;

use BrowscapHelper\Source\OutputAwareInterface;
use BrowscapHelper\Source\SourceInterface;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function mb_strlen;
use function sprintf;
use function str_pad;

use const STR_PAD_RIGHT;

final class ExistingTestsLoader
{
    /**
     * @param array<SourceInterface> $sources
     *
     * @return array<array<string, string>>|iterable
     *
     * @throws LogicException
     * @throws RuntimeException
     */
    public function getProperties(OutputInterface $output, array $sources): iterable
    {
        $baseMessage   = 'reading sources';
        $message       = $baseMessage . ' ...';
        $messageLength = mb_strlen($message);

        $output->writeln(str_pad($message, $messageLength, ' ', STR_PAD_RIGHT), OutputInterface::VERBOSITY_NORMAL);

        foreach ($sources as $source) {
            if ($source instanceof OutputAwareInterface) {
                $source->setOutput($output);
            }

            $baseMessage = sprintf('reading from source %s ', $source->getName());

            if (!$source->isReady($baseMessage)) {
                continue;
            }

            $message = $baseMessage . '...';

            if (mb_strlen($message) > $messageLength) {
                $messageLength = mb_strlen($message);
            }

            $output->write("\r" . '<info>' . str_pad($message, $messageLength, ' ', STR_PAD_RIGHT) . '</info>', false, OutputInterface::VERBOSITY_VERBOSE);

            yield from $source->getProperties($baseMessage, $messageLength);

            $message = $baseMessage . '- done';

            if (mb_strlen($message) > $messageLength) {
                $messageLength = mb_strlen($message);
            }

            $output->writeln("\r" . '<info>' . str_pad($message, $messageLength, ' ', STR_PAD_RIGHT) . '</info>', OutputInterface::VERBOSITY_VERBOSE);
        }
    }
}