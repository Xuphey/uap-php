<?php
/**
 * ua-parser
 *
 * Copyright (c) 2011-2012 Dave Olsen, http://dmolsen.com
 *
 * Released under the MIT license
 */
namespace UAParser\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use UAParser\Exception\InvalidArgumentException;
use UAParser\Exception\ReaderException;
use UAParser\Parser;
use UAParser\Result\Client;
use UAParser\Util\Logfile\AbstractReader;

class LogfileParseCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('ua-parser:parselog')
            ->setDescription('Parses the supplied webserver log file.')
            ->addArgument(
                'output',
                InputArgument::REQUIRED,
                'Path to output log file'
            )
            ->addOption(
                'log-file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Path to a webserver log file'
            )
            ->addOption(
                'log-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Path to webserver log directory'
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Include glob expressions for log files in the log directory',
                array('*.log', '*.log*.gz', '*.log*.bz2')
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Exclude glob expressions for log files in the log directory',
                array('*error*')
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('log-file') && !$input->getOption('log-dir')) {
            throw InvalidArgumentException::oneOfCommandArguments('log-file', 'log-dir');
        }

        $parser = Parser::create();
        $undefinedClients = array();
        /** @var $file SplFileInfo */
        $summary = '';
        foreach ($this->getFiles($input) as $file) {

            $path = $this->getPath($file);
            $lines = file($path);

            if (empty($lines)) {
                continue;
            }

            $firstLine = reset($lines);

            $reader = AbstractReader::factory($firstLine);
            if (!$reader) {
                $output->writeln(sprintf('Could not find reader for file "%s"', $file->getPathname()));
                $output->writeln('');
                continue;
            }

            $summary .= sprintf('Analyzing "%s"', $file->getPathname());
            $summary .= "\n";

            $count = 1;
            $totalCount = count($lines);
            foreach ($lines as $line) {

                try {
                    $userAgentString = $reader->read($line);
                } catch (ReaderException $e) {
                    if (!$input->getOption('no-prog'))
                        $count = $this->outputProgress($summary, 'E', $count, $totalCount);
                    continue;
                }

                $client = $parser->parse($userAgentString);

                $result = $this->getResult($client);
                if ($result !== '.') {
                    $undefinedClients[] = json_encode(
                        array($client->toString(), $userAgentString),
                        JSON_UNESCAPED_SLASHES
                    );
                }

                $count = $this->outputProgress($summary, $result, $count, $totalCount);
                if ($result === '.') {
                    $client = (array)$client;
                    $client['seq'] = $count;
                    $output->writeln(json_encode($client, JSON_PRETTY_PRINT));
                }
            }
            $this->outputProgress($summary, '', $count - 1, $totalCount, true);
            $output->writeln('');
        }

        $undefinedClients = $this->filter($undefinedClients);

        $fs = new Filesystem();
        $fs->dumpFile($input->getArgument('output'), $summary."\n".join(PHP_EOL, $undefinedClients));
    }

    private function outputProgress(&$summary, $result, $count, $totalCount, $end = false)
    {
        if (($count % 70) === 0 || $end) {
            $formatString = '%s  %' . strlen($totalCount) . 'd / %-' . strlen($totalCount) . 'd (%3d%%)';
            $result = $end ? str_repeat(' ', 70 - ($count % 70)) : $result;
            $summary .= sprintf($formatString, $result, $count, $totalCount, $count / $totalCount * 100)."\n";
        } else {
            $summary .= $result;
        }

        return $count + 1;
    }

    private function getResult(Client $client)
    {
        if ($client->device->family === 'Spider') {
            return 'C';
        } elseif ($client->ua->family === 'Other') {
            return 'U';
        } elseif ($client->os->family === 'Other') {
            return 'O';
        } elseif ($client->device->family === 'Generic Smartphone') {
            return 'S';
        } elseif ($client->device->family === 'Generic Feature Phone') {
            return 'F';
        }

        return '.';
    }

    private function getFiles(InputInterface $input)
    {
        $finder = Finder::create();

        if ($input->getOption('log-file')) {
            $file = $input->getOption('log-file');
            $finder->append(Finder::create()->in(dirname($file))->name(basename($file)));
        }

        if ($input->getOption('log-dir')) {
            $dirFinder = Finder::create()
                ->in($input->getOption('log-dir'));
            array_map(array($dirFinder, 'name'), $input->getOption('include'));
            array_map(array($dirFinder, 'notName'), $input->getOption('exclude'));

            $finder->append($dirFinder);
        }

        return $finder;
    }

    private function filter(array $lines)
    {
        return array_values(array_unique($lines));
    }

    private function getPath(SplFileInfo $file)
    {
        switch ($file->getExtension()) {
            case 'gz':
                $path = 'compress.zlib://' . $file->getPathname();
                break;

            case 'bz2':
                $path = 'compress.bzip2://' . $file->getPathname();
                break;

            default:
                $path = $file->getPathname();
                break;
        }

        return $path;
    }
}
