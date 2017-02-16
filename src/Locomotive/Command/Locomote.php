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

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;
use Locomotive\Database\DatabaseManager;
use Locomotive\Locomotive;

class Locomote extends Command
{
    /**
     * @var Logger
     **/
    protected $logger;

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
                InputArgument::OPTIONAL,
                'The full path to the SOURCE directory. May also be a colon-delimited source list.'
            )
             ->addArgument(
                'target',
                InputArgument::OPTIONAL,
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
            ->addOption(
                'max-retries',
                'm',
                InputOption::VALUE_REQUIRED,
                'Maximum retry attempts for a failed or interrupted transfer'
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
        if ($input->hasParameterOption('-vvv')) {
            $consoleLogLevel = Logger::DEBUG;
        } else if ($input->hasParameterOption('-vv')) {
            $consoleLogLevel = Logger::INFO;
        } else if ($input->hasParameterOption('-v')) {
            $consoleLogLevel = Logger::NOTICE;
        } else if ($input->hasParameterOption('-q')) {
            $consoleLogLevel = Logger::EMERGENCY;
        } else {
            $consoleLogLevel = Logger::ERROR;
        }

        $stdoutLogFormat = "%message%\n";
        $stdoutHandler = new StreamHandler('php://stdout', $consoleLogLevel);
        $stdoutHandler->setFormatter(new ColoredLineFormatter(null, $stdoutLogFormat));

        $rotatingFileFormat = "[%datetime%] %channel%.%level_name%: %message%\n";
        $rotatingFileHandler = new RotatingFileHandler(BASEPATH . '/app/storage/logs/locomotive.log', 0, Logger::DEBUG);
        $rotatingFileHandler->setFormatter(new LineFormatter($rotatingFileFormat));

        $this->logger = new Logger('loco');
        $this->logger->pushHandler($stdoutHandler);
        $this->logger->pushHandler($rotatingFileHandler);
    }

    /**
     * Executes the command.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return void
     **/
    protected function execute(InputInterface $input, OutputInterface $output)
    {
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
            $this->logger->notice('Locomotive is already running with these arguments in another process.');

            return 0;
        }

        // setup database connection and perform any necessary maintenance
        $DBM = new DatabaseManager($output, $this->logger);
        $DBM->doMaintenance()
            ->connect();
        $DB = $DBM->getConnection();

        // instantiate Locomotive
        $locomotive = new Locomotive($input, $output, $this->logger, $DB);

        // initial probing for general lftp state
        $lftpQueue = $locomotive->getLftpStatus();

        if ($locomotive->isLftpBackgrounded) {
            // parse the lftp queue and set $locomotive class variables
            // for lftp queued items and available slots
            $locomotive->parseLftpQueue($lftpQueue);
        }

        // run Locomotive queue updates, transfers, and file handling
        $locomotive
            ->setLimits()
            ->updateLocalQueue()
            ->initiateTransfers()
            ->moveFinished()
            ->removeSourceFiles();
        
        // write main status to output: new transfers
        if ($locomotive->newTransfers) {
            $thisLogger = &$this->logger;
            $locomotive->newTransfers->each(function($item) use ($thisLogger) {
                $thisLogger->info('New transfer started: ' . $item->getBasename());
            });
        } else {
            $this->logger->info('Locomotive did not start any new transfers.');
        }

        // write main status to output: moved items
        if ($locomotive->movedItems->count() > 0) {
            $thisLogger = &$this->logger;
            $locomotive->movedItems->each(function($item) use ($thisLogger) {
                $thisLogger->info('Finished item moved: ' . $item->name);
            });
        } else {
            $this->logger->info('Locomotive did not move any transfered items.');
        }

        // manually releasing lock
        $lock->release();
    }
}
