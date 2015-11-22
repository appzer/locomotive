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
 */

namespace Locomotive;

use Symfony\Component\Console\Logger\ConsoleLogger;

class Lftp
{
    /**
     * @var Array
     **/
    protected $options = array();

    /**
     * @var ConsoleLogger
     **/
    protected $logger;

    /**
     * The current command.
     *
     * @var String
     **/
    protected $command;

    /**
     * A list of all commands that have been executed.
     *
     * @var Array
     **/
    protected $commandLog = array();

    /**
     * The current commands array.
     *
     * @var Array
     **/
    protected $commands = array();

    /**
     * The connection command.
     *
     * @var String
     **/
    protected $connection;

    /**
     * Class Constructor.
     *
     * @param Array $options A place to pass through some settings
     */
    public function __construct($options, ConsoleLogger $logger)
    {
        $this->options = $options;
        $this->logger = $logger;
    }

    public function connect()
    {
        // handle connections w/ ssh key file
        if ($this->options['private-keyfile']) {
            $cmd = "set sftp:connect-program \"ssh -a -x -i " . $this->options['private-keyfile'] . "\";"
                . " connect -p " . $this->options['port'] . " -u "
                . $this->options['username'] . "," . $this->options['password']
                . " sftp://" . $this->options['host']
            ;
        // try with username and password
        } else {
            $cmd = "connect -p " . $this->options['port'] . " -u "
                . $this->options['username'] . "," . $this->options['password']
                . " sftp://" . $this->options['host']
            ;
        }

        $this->connection = $cmd;

        return $this;
    }

    public function setSpeedLimit($limit)
    {
        $cmd = "set net:limit-total-rate $limit";

        $this->addCommand($cmd);

        $this->logger->info("Speed limit set to $limit Bps.");

        return $this;
    }

    public function setQueueTransferLimit($limit)
    {
        $cmd = "set cmd:queue-parallel $limit";

        $this->addCommand($cmd);

        $this->logger->info("Parallel transfer limit set to $limit item(s).");

        return $this;
    }

    public function listDir($path, $cls = true)
    {
        if ($cls === true) {
            $cmd = "cls $path";
        } else {
            $cmd = "ls $path";
        }

        $this->addCommand($cmd);

        return $this;
    }

    public function mirrorDir($path, $pget = false, $parallel = false, $queue = false)
    {
        // escape problem characters in path
        $path = str_replace(" ", "\\ ", addslashes($path));

        $cmd = 'mirror -c';

        if ($pget) {
            $cmd .= " --use-pget-n=$pget";
        }

        if ($parallel) {
            $cmd .= " --parallel=$parallel";
        }

        $cmd .= " $path " . $this->options['working-dir'];

        if ($queue === true) {
            $cmd = "queue $cmd";
        }

        $this->addCommand($cmd);

        return $this;
    }

    public function pgetFile($path, $conn = false, $queue = false)
    {
        // escape problem characters in path
        $path = str_replace(" ", "\\ ", addslashes($path));

        $cmd = 'pget -c';

        if ($conn) {
            $cmd .= " -n $conn";
        }

        $cmd .= " $path -o " . $this->options['working-dir'];

        if ($queue === true) {
            $cmd = "queue $cmd";
        }

        $this->addCommand($cmd);

        return $this;
    }

    public function execute($detach = false, $attach = false, $terminalId = null)
    {
        // set connection command
        $this->connect();

        // concatenate all loaded commands
        $this->command = "$this->connection; ";

        if ($attach === true) {
            $this->command .= 'echo "';
        }

        foreach ($this->commands as $cmd) {
            // TODO: escape command ???
            $this->command .= "$cmd; ";
        }
        $this->command = rtrim($this->command);

        if ($attach === true) {
            $this->command .= '" | lftp -c attach';

            if (! is_null($terminalId)) {
                $this->command .= " $terminalId";
            }
        }
        
        // execute command
        $this->logger->debug("Executing lftp commands: $this->command");

        if ($detach === true) {
            $this->command .= ' exit parent;';
            exec("lftp -c '$this->command' > /dev/null 2>&1 & echo $!", $output);

            // assuming an OK exit
            $exitCode = 0;
        } else {
            exec("lftp -c '$this->command' 2>&1", $output, $exitCode);
        }

        // deal with exit code failures
        if ($exitCode != 0) {
            $this->logger->error(implode(' ', $output));

            exit(1);
        }

        // add command to log
        array_push($this->commandLog, $this->command);

        // clear command variables
        unset($this->command);
        unset($this->commands);
        
        return $output;
    }

    public function addCommand($command)
    {
        $this->commands[] = $command;

        return $this;
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getCommandLog()
    {
        return $this->commandLog;
    }

    public function lastCommand()
    {
        $commandLog = $this->getCommandLog();
        $lastCommand = end($commandLog);

        return $lastCommand;
    }
}