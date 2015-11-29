<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package     Locomotive
 * @subpackage  Locomotive\Command
 */

namespace Locomotive\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Locomotive\Database\DatabaseManager;
use Locomotive\Locomotive;

class Locomote extends Command
{
    private $formatLevelMap;

    /**
     * Sets command options and validates input.
     **/
    protected function configure()
    {
        $this->setName('locomote');

        $this
            ->addArgument(
                'host',
                InputArgument::REQUIRED,
                'A URL or resolvable Hostname for the remote source.'
            )
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'The full path to the SOURCE directory. May also be a colon-delimited source list.'
            )
             ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'The full path to the TARGET directory. May also be a colon-delimited target list map.'
            )
        ;

        $this
            ->addOption(
                'public-keyfile',
                null,
                InputOption::VALUE_REQUIRED,
                'A public key file to use for SSH authentication'
            )
            ->addOption(
                'private-keyfile',
                null,
                InputOption::VALUE_REQUIRED,
                'A private key file to use for SSH authentication'
            )
            ->addOption(
                'username',
                'u',
                InputOption::VALUE_REQUIRED,
                'Username for SSH login'
            )
            ->addOption(
                'password',
                'p',
                InputOption::VALUE_REQUIRED,
                'Password for SSH login'
            )
            ->addOption(
                'port',
                'o',
                InputOption::VALUE_REQUIRED,
                'The port number if the source server is listening for SSH on a non-standard port.'
            )
            ->addOption(
                'working-dir',
                'w',
                InputOption::VALUE_REQUIRED,
                'A full path to overide the working directory for Locomotive'
            )
            ->addOption(
                'speed-limit',
                's',
                InputOption::VALUE_REQUIRED,
                'Global speed limit in bytes (defaults to unlimited)'
            )
            ->addOption(
                'connection-limit',
                'c',
                InputOption::VALUE_REQUIRED,
                'Transfer connection limit (defaults to 25)'
            )
            ->addOption(
                'transfer-limit',
                't',
                InputOption::VALUE_REQUIRED,
                'Global concurrent item transfer limit (defaults to 5)'
            )
        ;
    }

    /**
     * Initial settings for the the command.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     **/
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // setup some custom coloring for log levels
        $this->formatLevelMap = [
            LogLevel::DEBUG     => 'fg=default',
            LogLevel::INFO      => 'fg=default',
            LogLevel::NOTICE    => 'fg=green',
            LogLevel::WARNING   => 'fg=yellow',
        ];
    }

    /**
     * Executes the command.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     **/
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = new ConsoleLogger($output, array(), $this->formatLevelMap);
        $outputFormatter = $this->getHelper('formatter');

        // creating a unique lock id to enable running Locomotive as multiple,
        // concurrent instances.
        $lockid = md5(
            serialize([
                $input->getArguments(),
                $input->getOptions(),
            ]
        ));

        // create a lock
        $lock = new LockHandler("locomotive-$lockid");
        if (! $lock->lock()) {
            $logger->notice('Locomotive is already running with these arguments in another process.');

            return 0;
        }

        // setup database connection and perform any necessary maintenance
        $DBM = new DatabaseManager($output, $logger);
        $DBM->doMaintenance()
            ->connect();
        $DB = $DBM->getConnection();

        // instantiate Locomotive
        $locomotive = new Locomotive($input, $output, $logger, $DB);

        // initial probing for general lftp state
        $lftpQueue = $locomotive->getLftpStatus();

        if ($locomotive->isLftpBackgrounded) {
            // parse the lftp queue and set $locomotive class variables
            // for lftp queued items and available slots
            $locomotive->parseLftpQueue($lftpQueue);
        }

        // set lftp global limits
        $locomotive->setLimits();

        // begin new transfers via lftp
        $locomotive->initiateTransfers();

        // update local DB queue active/finished flags
        $locomotive->updateLocalQueue();

        // TODO: source-side file cleanup (if enabled via options)
        $locomotive->removeSourceFiles();

        // move finished local transfered items to their final target detination
        $locomotive->moveFinished();
        
        // write main status to output
        if ($locomotive->newTransfers) {
            $output->writeln('New transfers were started:');
            $messageLines = array();
            $locomotive->newTransfers->each(function($item) use (&$messageLines) {
                $messageLines[] = $item->getBasename();
            });
            $formattedBlock = $outputFormatter->formatBlock($messageLines, 'info');
            $output->writeln($formattedBlock);
        } else {
            $output->writeln('Locomotive did not start any new transfers.');
        }

        if ($locomotive->movedItems) {
            $output->writeln('Finished transfers were moved:');
            $messageLines = array();
            $locomotive->movedItems->each(function($item) use (&$messageLines) {
                $messageLines[] = $item->name;
            });
            $formattedBlock = $outputFormatter->formatBlock($messageLines, 'info');
            $output->writeln($formattedBlock);
        } else {
            $output->writeln('Locomotive did not move any transfered items.');
        }

        // manually releasing lock
        $lock->release();
    }
}
