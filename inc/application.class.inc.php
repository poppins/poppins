<?php

class Application
{
    private $version;
    
    public $intervals;

    public $settings;
    
    public $Cmd;
    
    private $messages;
    
    private $options;

    public $start_time;

    function __construct($appname, $version)
    {
        $this->intervals = ['incremental', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'];

        $this->appname = $appname;

        $this->version = $version;

        $this->start_time = date('U');
    }

    function fail($message, $error = 'generic')
    {
        //compose message
        $output = [];
        $output []= "FATAL ERROR: Application failed!";
        $output []= "MESSAGE: $message";
        if(isset($this->options['d']))
        {
            $output []= "COMMAND STACK:";
            foreach($this->Cmd->commands as $c)
            {
                $output []= $c;
            }
        }
        
        $this->out(implode("\n", $output), 'error');
        //quit
        $this->quit("SCRIPT FAILED!", $error);
    }

    function init()
    {
        #####################################
        # HELP
        #####################################\
        $CLI_SHORT_OPTS = ["c:d"];
        $this->options = getopt(implode('', $CLI_SHORT_OPTS));
        if (!count($this->options) || @$argv[1] == '--help')
        {
            //print "Usage: " . $_SERVER['PHP_SELF'] . " -c {configfile} [-d] [--longoptions]\n";
            $documentation = file_get_contents(dirname(__FILE__).'/../documentation.txt');
            print $this->appname.' '.$this->version."\n\n";
            print "$documentation\n";
            die();
        }
        #####################################
        # START
        #####################################
        $this->out("$this->appname v$this->version - SCRIPT STARTED " . date('Y-m-d H:i:s', $this->start_time), 'title');
        $this->out('local environment', 'header');
        #####################################
        # CHECK OS
        #####################################
        $this->out('Check local operating system...');
        $OS = trim(shell_exec('uname'));
        if (!in_array($OS, ['Linux', 'SunOS']))
        {
            die("Local OS currently not supported!\n");
        }
        #####################################
        # SETUP COMMANDS
        #####################################
        $Cmd = CmdFactory::create($OS);
        //load commands
        $this->Cmd = $Cmd;
        #####################################
        # CHECK ROOT USER 
        #####################################
        $this->out('Check local user...');
        $whoami = $this->Cmd->exe('whoami');
        if ($whoami != "root")
        {
            die("You must run this script as root!\n");
        }
        #####################################
        # PARSE CONFIG FILE
        #####################################
        $this->out("configuration file", 'header');
        //validate config file
        if (isset($this->options['c']))
        {
            $configfile = $this->options['c'];
            if (!file_exists($configfile))
            {
                $this->fail("Config file not found!");
            }
            else
            {
                $this->settings = parse_ini_file($configfile, 1);
                $ini_options= [];
                foreach($this->settings as $k => $v)
                {
                    foreach($v as $kk => $vv)
                    {
                        $option = "$k-$kk::";
                        $ini_options[]= $option;
                    }
                }
                $this->options = getopt(implode('', $CLI_SHORT_OPTS), $ini_options);
                //override ini with cli options
                foreach($this->options as $k => $v)
                {
                    if(in_array("$k::", $ini_options))
                    {
                        $p = explode('-', $k);
                        $k1 = $p[0];
                        unset ($p[0]);
                        $k2 = implode('', $p);
                        $this->settings[$k1][$k2] = $v;
                    }
                }
                //add data
                $this->settings['local']['os'] = $OS;
                $this->settings['application']['name'] = $this->appname;
            }
        }
        else
        {
            $this->fail("Option -c {configfile} is required!");
        }
        //trim spaces
        $this->out("Check configuration syntax (spaces and trailing slashes not allowed)...");
        foreach ($this->settings as $k => $v)
        {
            foreach ($v as $kk => $vv)
            {
                $this->settings[$k][$kk] = str_replace(" ", "", $vv);
                //No trailing slashes
                if (preg_match('/\/$/', $vv))
                {
                    $this->fail("No trailing slashes allowed in config file! $kk = $vv...");
                }
            }
        }
        //check if there is backup is configured
        if(!count($this->settings['included']) && $this->settings['mysql']['enabled'] != 'yes')
        {
            $this->fail("No directories configured or MySQL to backup...");
        }
        //validate included/excluded syntax
        $included = array_keys($this->settings['included']);
        $excluded = array_keys($this->settings['excluded']);
        foreach($excluded as $e)
        {
            if(!in_array($e, $included))
            {
                $this->fail("Excluded directory $e not indexed in included directories!");
            }
        }
        //validate snapshot config
        $this->out("Check snapshot config...");
        foreach($this->settings['snapshots'] as $k => $v)
        {
            //check syntax of key
            if($k != 'incremental' && !preg_match('/^[0-9]+-(' .  implode("|", $this->intervals).')$/', $k))
            {
                $this->fail("Error in snapshot configuration, $k not supported!");
            }
            //check if value is an integer
            //check syntax of value
            if(!preg_match("/^[0-9]+$/", $v))
            {
                $this->fail("Error in snapshot configuration, value for $k is not an integer!");
            }
        }
        #####################################
        # CHECK LOG DIR
        #####################################
        //check log dir early so we can log stuff
        $this->out('Check logdir...');
        //to avoid confusion, an absolute path is required
        if (!preg_match('/^\//', $this->settings['local']['logdir']))
        {
            $this->fail("logdir must be an absolute path!");
        }
        //validate dir, create if required
        if (!file_exists($this->settings['local']['logdir']))
        {
            $this->out('Create logdir  ' . $this->settings['local']['logdir'] . '...');
            $this->Cmd->exe("mkdir -p " . $this->settings['local']['logdir']);
        }
        #####################################
        # CHECK REMOTE PARAMS
        #####################################
        $this->out('Check remote parameters...');
        //validate user
        $this->settings['remote']['user'] = ( empty($this->settings['remote']['user'])) ? 'root' : $this->settings['remote']['user'];
        //check remote host
        if (empty($this->settings['remote']['host']))
        {
            $this->fail("Remote host is not configured!! Specify it in the ini file or on the command line!");
        }
        else
        {
            $_h = $this->settings['remote']['host'];
            $_u = $this->settings['remote']['user'];
            $ping = $this->Cmd->exe("ping -c 1 $_h > /dev/null 2>&1 && echo OK || false");
            if (!$ping)
            {
                $this->fail("Cannot reach remote host $_u@$_h!");
            }
        }
        $this->out('Check ssh connection...');
        $sshtest = $this->Cmd->exe("ssh -o BatchMode=yes $_u@$_h echo OK");
        if (!$sshtest)
        {
            $this->fail("SSH login attempt failed at remote host $_u@$_h!");
        }
        //get remote os
        $this->settings['remote']['os'] = $this->Cmd->exe("ssh $_u@$_h uname");
        //get distro
        foreach (['Ubuntu', 'Debian', 'SunOS', 'OpenIndiana', 'Red Hat', 'CentOS', 'Fedora'] as $d)
        {
            $output = $this->Cmd->exe("ssh $_u@$_h 'cat /etc/*release'");
            if (preg_match("/$d/i", $output))
            {
                $this->settings['remote']['distro'] = $d;
                break;
            }
        }
        #####################################
        # CHECK DEPENDENCIES
        #####################################
        $this->out('Check dependencies...');
        $dependencies = [];
        //remote
        if(in_array($this->settings['remote']['distro'], ['Debian', 'Ubuntu'])) $dependencies['remote']['aptitude'] = 'aptitude --version'; 
        $dependencies['remote']['rsync'] = 'rsync --version'; 
        //local
        $dependencies['local']['rsync'] = 'rsync --version'; 
        $dependencies['local']['grep'] = 'grep --version'; 
        //iterate packages
        foreach ($dependencies as $host => $packages)
        {
            foreach ($packages as $package => $command)
            {
                //check if installed
                $command = ($host == 'remote')? "ssh $_u@$_h '" . $command."'":$command;
                $this->Cmd->exe($command);
                if ($this->Cmd->is_error())
                {
                    $this->fail("Package $package installed on $host machine?");
                }
            }
        }
        #####################################
        # CHECK ROOT DIR & FILE SYSTEM
        #####################################
        $this->out('Check rootdir...');
        //to avoid confusion, an absolute path is required
        if (!preg_match('/^\//', $this->settings['local']['rootdir']))
        {
            $this->fail("rootdir must be an absolute path!");
        }
        //root dir must exist!
        if (!file_exists($this->settings['local']['rootdir']))
        {
            $this->fail("Root dir '" . $this->settings['local']['rootdir'] . "' does not exist!");
        }
        //check filesystem config
        $this->out('Check filesystem config...');
        $supported_fs = ['default', 'ZFS', 'BTRFS'];
        if(!in_array($this->settings['local']['filesystem'], $supported_fs))
        {
            $this->fail('Local filesystem not supported! Supported: '.implode(",", $supported_fs));
        }
        //validate filesystem
        switch($this->settings['local']['filesystem'])
        {
            case 'ZFS':
            case 'BTRFS':
                $fs = $this->Cmd->exe("df -P -T ".$this->settings['local']['rootdir']." | tail -n +2 | awk '{print $2}'");
                if($fs != strtolower($this->settings['local']['filesystem']))
                {
                    $this->fail('Rootdir is not a '.$this->settings['local']['filesystem'].' filesystem!');
                }
                break;
            default:
        }
        //validate root dir and create if required
        switch ($this->settings['local']['filesystem'])
        {
            //if using ZFS, we want a mount point
            case 'ZFS':
                //check if mount point
                $rootdir_check = $this->Cmd->exe("zfs get -H -o value mountpoint ".$this->settings['local']['rootdir']);
                if($rootdir_check != $this->settings['local']['rootdir'])
                {
                    $this->fail("No ZFS mount point " . $this->settings['local']['rootdir'] . " found!");
                }
                //validate if dataset name and mountpoint are the same
                $zfs_info = $this->Cmd->exe("zfs list | grep '".$this->settings['local']['rootdir']."$'");
                $a = explode(' ', $zfs_info);
                if('/'.reset($a) != end($a))
                {
                    $this->fail('ZFS name and mountpoint do not match!');
                }
                break;
            default:
                if (!file_exists($this->settings['local']['rootdir']))
                {
                    $this->Cmd->exe("mkdir -p " . $this->settings['local']['rootdir']);
                    if ($this->Cmd->is_error())
                    {
                        $this->fail("Could not create directory:  " . $this->settings['local']['rootdir'] . "!");
                    }
                }
        }
        #####################################
        # CHECK HOST DIR
        #####################################
        $this->out('Check host...');
        $hostdirname = ($this->settings['local']['hostdir-name'])? $this->settings['local']['hostdir-name']:$this->settings['remote']['host'];
        //check if absolute path
        if (preg_match('/^\//', $hostdirname))
        {
            $this->fail("hostname may not contain slashes!");
        }
        $this->settings['local']['hostdir'] = $this->settings['local']['rootdir'] . '/' . $hostdirname;
        //validate host dir and create if required
        switch ($this->settings['local']['filesystem'])
        {
            //if using ZFS, we want to check if a filesystem is in place, otherwise, create it
            case 'ZFS':
                $hostdir_check = $this->Cmd->exe("zfs get -H -o value mountpoint " . $this->settings['local']['hostdir']);
                if ($hostdir_check != $this->settings['local']['hostdir'])
                {
                    if ($this->settings['local']['hostdir-create'] == 'yes')
                    {
                        $zfs_fs = preg_replace('/^\//', '', $this->settings['local']['hostdir']);
                        $this->out("ZFS filesystem " . $zfs_fs . " does not exist, creating zfs filesystem..");
                        $this->Cmd->exe("zfs create " . $zfs_fs);
                        if ($this->Cmd->is_error())
                        {
                            $this->fail("Could not create zfs filesystem:  " . $zfs_fs . "!");
                        }
                    }
                    else
                    {
                        $this->fail("Directory " . $this->settings['local']['hostdir'] . " does not exist! Not allowed to create it..");
                    }
                }
                //validate if dataset name and mountpoint are the same
                $zfs_info = $this->Cmd->exe("zfs list | grep '".$this->settings['local']['hostdir']."$'");
                $a = explode(' ', $zfs_info);
                if ('/' . reset($a) != end($a))
                {
                    $this->fail('ZFS name and mountpoint do not match!');
                }
                break;
            default:
                //check if dir exists
                if (!file_exists($this->settings['local']['hostdir']))
                {
                    if ($this->settings['local']['hostdir-create'] == 'yes')
                    {
                        $this->out("Directory " . $this->settings['local']['hostdir'] . " does not exist, creating it..");
                        $this->Cmd->exe("mkdir -p " . $this->settings['local']['hostdir']);
                        if ($this->Cmd->is_error())
                        {
                            $this->fail("Could not create directory:  " . $this->settings['local']['hostdir'] . "!");
                        }
                    }
                    else
                    {
                        $this->fail("Directory " . $this->settings['local']['hostdir'] . " does not exist! Not allowed to create it..");
                    }
                }
                break;
        }
        #####################################
        # CHECK RSYNC DIR
        #####################################
        //set syncdir
        switch($this->settings['local']['filesystem'])
        {
            case 'ZFS':
            case 'BTRFS':
                $rsyncdir = 'rsync.'.strtolower($this->settings['local']['filesystem']);
                break;
            default:
                $rsyncdir = 'rsync.dir';
        }
        $this->settings['local']['rsyncdir'] = $this->settings['local']['hostdir'].'/'.$rsyncdir;
        #####################################
        # MYSQL
        #####################################
        if ($this->settings['mysql']['enabled'] == 'yes' && !$this->settings['mysql']['configdirs'])
        {
            $this->settings['mysql']['configdirs'] = '/root';
        }
        ######################################
        # DUMP ALL SETTINGS
        #####################################
        $this->out('LIST CONFIGURATION', 'header');
        $output = [];
        ksort($this->settings);
        foreach ($this->settings as $k => $v)
        {
            if (is_array($v))
            {
                ksort($v);
                $output [] = "\n[$k]";
                foreach ($v as $kk => $vv)
                {
                    $vv = ($vv) ? $vv : '""';
                    $output [] = sprintf("%s = %s", $kk, $vv);
                }
            }
            else
            {
                $v = ($v) ? $v : '""';
                $output [] = sprintf("%s = %s", $k, $v);
            }
        }
        $this->out(trim(implode("\n", $output)));
    }
    
    function log($message)
    {
        $this->messages [] = $message;
    }

    function out($message, $type = 'default')
    {
        $content = [];
        switch ($type)
        {
            case 'error':
                $l = '|||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||';
                $content [] = '';
                $content [] = $l;
                $content [] = $l;
                $content [] = $message;
                $content [] = $l;
                $content [] = $l;
                $content [] = '';
                break;
            case 'header':
                $l = "---------------------------------------------------------------------------------------";
                $content [] = $l;
                $content [] = strtoupper($message);
                $content [] = $l;
                break;
            case 'indent':
                $content [] = "-----------> " . $message;
                break;
            case 'title':
                $l = "%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%";
                $content [] = $l;
                $content [] = $message;
                $content [] = $l;
                break;
            case 'warning':
                $l = '|||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||';
                $content [] = '';
                $content [] = $l;
                $content [] = $message;
                $content [] = $l;
                $content [] = '';
                break;
            case 'default':
                $content [] = $message;
                break;
        }
        $message = implode("\n", $content);
        //log to file
        $this->log($message);
        //print to screen
        //print $message."\n";
    }

    function quit($message = '', $error = false)
    {
        $this->out("$this->appname v$this->version - SCRIPT ENDED " . date('Y-m-d H:i:s'), 'title');
        //log message
        if ($message)
        {
            $this->log($message);
        }
        //time script
        $lapse = date('U') - $this->start_time;
        $lapse = gmdate('H:i:s', $lapse);
        $this->log("Script time (HH:MM:SS) : $lapse");
        //remove LOCK file if exists
        if ($error != 'LOCKED' && file_exists(@$this->settings['local']['hostdir'] . "/LOCK"))
        {
            $this->log("Remove LOCK file...");
            $this->log("");
            $this->Cmd->exe('{RM} ' . $this->settings['local']['hostdir'] . "/LOCK");
        }
        //format messages
        $messages = implode("\n", $this->messages);
        //content
        $content = [];
        $content [] = $messages;
        //log to file
        //script returned no errors if set to false
        $suffix = ($error) ? 'error' : 'success';
        $logfile = $this->settings['local']['logdir'] . '/' . $this->settings['remote']['host'] . '.' . date('Y-m-d_His', $this->start_time) . '.poppins.' . $suffix . '.log';
        $content [] = 'Create logfile ' . $logfile . '...';
        $this->Cmd->exe("touch " . $logfile);
        if ($this->Cmd->is_error())
        {
            $this->fail("Cannot create log file!");
        }
        //write to log
        $content [] = 'Write to log file...';
        $success = file_put_contents($logfile, $messages);
        if (!$success)
        {
            $content [] = 'FAILED!';
        }
        //be polite
        $content [] = "Bye...";
        //last newline
        $content [] = "";
        //output
        print implode("\n", $content);
        die();
    }

    function succeed()
    {
        $this->out("Final report", 'header');
        $this->out("SCRIPT RAN SUCCESFULLY!");
        #####################################
        # REPORT
        #####################################
        //list disk usage
        foreach([$this->settings['local']['hostdir']] as $dir)
        {
            $du = $this->Cmd->exe("du -sh $dir");
            $this->out("Disk Usage ($dir):");
            $this->out("$du");
        }
        $this->out("Done!");
        $this->quit();
    }

}
