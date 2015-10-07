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

    function fail($message = '', $error = 'generic')
    {
        //compose message
        $output = [];
        $output []= "FATAL ERROR: Application failed!";
        $output []= "MESSAGE: $message";
        $this->out(implode("\n", $output), 'error');
        //quit
        $this->quit("SCRIPT FAILED!", $error);
    }

    function init()
    {
        #####################################
        # HELP
        #####################################\
        $CLI_SHORT_OPTS = ["c:dhv"];
        $CLI_LONG_OPTS = ["version", "help"];
        $this->options = getopt(implode('', $CLI_SHORT_OPTS), $CLI_LONG_OPTS);
        if (!count($this->options))
        {
            $content = file_get_contents(dirname(__FILE__).'/../documentation.txt');
            preg_match('/SYNOPSIS\n(.*?)\n/s', $content, $match);
            print "Usage: " . trim($match[1]) . "\n";
            $this->abort();
        }
        elseif(isset($this->options['h']) || isset($this->options['help']))
        {
            print $this->appname.' '.$this->version."\n\n";
            $content = file_get_contents(dirname(__FILE__).'/../documentation.txt');
            print "$content\n";
            $this->abort();
        }
        elseif(isset($this->options['v']) || isset($this->options['version']))
        {
            print $this->appname.' version '.$this->version."\n";
            $content = file_get_contents(dirname(__FILE__).'/../license.txt');
            print "$content\n";
            $this->abort();
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
            $this->abort("Local OS currently not supported!");
        }
        #####################################
        # SETUP COMMANDS
        #####################################
        $Cmd = CmdFactory::create($OS);
        //load commands
        $this->Cmd = $Cmd;
        #####################################
        # LOAD OPTIONS FROM CONFIG FILE
        #####################################
        $this->out("configuration file", 'header');
        //validate config file
        if (isset($this->options['c']))
        {
            $configfile = $this->options['c'];
            if (!file_exists($configfile))
            {
                $this->abort("Config file not found!");
            }
            elseif (!preg_match('/^.+\.poppins\.ini$/', $configfile))
            {
                $this->abort("Wrong ini file format: {hostname}.poppins.ini!");
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
            $this->abort("Option -c {configfile} is required!");
        }
        #####################################
        # PARSE CONFIG FILE
        #####################################
        //trim spaces
        $this->out("Check configuration syntax (spaces and trailing slashes not allowed)...");
        foreach ($this->settings as $k => $v)
        {
            //do not validate if included or excluded directories
            if(in_array($k, ['included', 'excluded']))
            {
                //trim commas
                foreach ($v as $kk => $vv)
                {
                    $this->settings[$k][$kk] = preg_replace('/\s?,\s?/', ',', $vv);
                }
            }
            else
            {
                //loop thru key/value pairs
                foreach ($v as $kk => $vv)
                {
                    //check for white space
                    $vv1 = str_replace(" ", "", $vv);
                    if($vv != $vv1)
                    {
                        $this->out('Config values may not contain spaces. Value for key "['.$k.'] '.$kk.'" is trimmed! New value is '.$vv1, 'warning');
                        $this->settings[$k][$kk] = $vv1;
                    }
                    //No trailing slashes
                    if (preg_match('/\/$/', $vv))
                    {
                        $this->fail("No trailing slashes allowed in config file! $kk = $vv...");
                    }
                }
            }
        }
        //check if there is backup is configured
        if(!count($this->settings['included']) && $this->settings['mysql']['enabled'] != 'yes')
        {
            $this->fail("No directories configured for backup nor MySQL configured. Nothing to do...");
        }
        //validate spaces in keys of included section
        foreach ($this->settings['included'] as $k => $v)
        {
            $k1 = str_replace(' ', '\ ', stripslashes($k));
            if ($k != $k1)
            {
                $this->fail("You must escape white space in [included] section! Aborting...");
            }
        }
        //validate spaces in values of included/excluded section
        foreach(['included', 'excluded'] as $section)
        {
            foreach ($this->settings[$section] as $k => $v)
            {
                $v1 = str_replace(' ', '\ ', stripslashes($v));
                if ($v != $v1)
                {
                    $this->fail("You must escape white space in [$section] section! Aborting...");
                }
            }
        }
        //validate included/excluded syntax
        $included = array_keys($this->settings['included']);
        $excluded = array_keys($this->settings['excluded']);
        foreach($excluded as $e)
        {
            if(!in_array($e, $included))
            {
                $this->fail("Unknown excluded directory index \"$e\"!");
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
            if($this->Cmd->is_error())
            {
                $this->fail('Cannot create log dir '.$this->settings['local']['logdir']);
            }
        }
        #####################################
        # CHECK REMOTE PARAMS
        #####################################
        $this->out('Check remote parameters...');
        //validate user
        $this->settings['remote']['user'] = ( empty($this->settings['remote']['user'])) ? $this->Cmd->exe('whoami') : $this->settings['remote']['user'];
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
        //Debian - Ubuntu
        if(in_array($this->settings['remote']['distro'], ['Debian', 'Ubuntu']))
        {
            $dependencies['remote']['aptitude'] = 'aptitude --version'; 
        }
        //Red Hat - Fedora
        if(in_array($this->settings['remote']['distro'], ['Red Hat', 'CentOS', 'Fedora']))
        {
            //yum is nice though rpm will suffice, no hard dependency needed
            //$dependencies['remote']['yum-utils'] = 'yumdb --version'; 
        }
        $dependencies['remote']['rsync'] = 'rsync --version'; 
        //local
        $dependencies['local']['rsync'] = 'rsync --version'; 
        $dependencies['local']['grep'] = '{GREP} --version'; 
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
                $fs = $this->Cmd->exe("{DF} -P -T ".$this->settings['local']['rootdir']." | tail -n +2 | awk '{print $2}'");
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
        $this->out('LIST CONFIGURATION @'.$this->settings['remote']['host'], 'header');
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
                $l1 = '$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$ ERROR $$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$';
                $l2 = '$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$';
                $content [] = '';
                $content [] = $l1;
                $content [] = $message;
                $content [] = $l2;
                $content [] = '';
                break;
            case 'header':
                $l = "-----------------------------------------------------------------------------------------";
                $content [] = $l;
                $content [] = strtoupper($message);
                $content [] = $l;
                break;
            case 'indent':
                $content [] = "-----------> " . $message;
                break;
            case 'title':
                $l = "%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%";
                $content [] = $l;
                $content [] = $message;
                $content [] = $l;
                break;
            case 'warning':
                $l1 = '||||||||||||||||||||||||||||||||||| WARNING ||||||||||||||||||||||||||||||||||||||||||||';
                $l2 = '||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||';
                $content [] = '';
                $content [] = $l1;
                $content [] = $message;
                $content [] = $l2;
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

    function abort($message = '')
    {
        if($message)
        {
            $message .= "\n";
        }
        die($message);
    }
    
    function quit($message = '', $error = false)
    {
        //debug output
        if(isset($this->options['d']))
        {
            $this->out("COMMAND STACK", 'header');
            $output = [];
            foreach($this->Cmd->commands as $c)
            {
                $output []= $c;
            }
            $output []= "";
            $this->out(implode("\n", $output));
        }
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
        #####################################
        # LOG TO FILE
        #####################################
        //write to log
        $content [] = 'Write to log file...';
        if (is_dir($this->settings['local']['logdir']))
        {
            if (!empty($this->settings['remote']['host']))
            {
                //script returned no errors if set to false
                $suffix = ($error) ? 'error' : 'success';
                $logfile = $this->settings['local']['logdir'] . '/' . $this->settings['remote']['host'] . '.' . date('Y-m-d_His', $this->start_time) . '.poppins.' . $suffix . '.log';
                $content [] = 'Create logfile ' . $logfile . '...';
                //create file
                $this->Cmd->exe("touch " . $logfile);
                
                if ($this->Cmd->is_error())
                {
                    $content []= 'WARNING! Cannot write to logfile. Cannot create log file!';
                }
                else
                {
                    $success = file_put_contents($logfile, $messages);
                    if (!$success)
                    {
                        $content []= 'WARNING! Cannot write to logfile. Write protected?';
                    }
                }
            }
            else
            {
                $content []= 'WARNING! Cannot write to logfile. Remote host not specified!';
            }
        }
        else
        {
            $content []= 'WARNING! Cannot write to logfile. Log directory not created!';
        }
        //be polite
        $content [] = "Bye...";
        //last newline
        $content [] = "";
        //output
        print implode("\n", $content);
        $this->abort();
    }

    function succeed()
    {
        #####################################
        # REPORT
        #####################################
        //list disk usage
        if ($this->settings['log']['local-disk-usage'])
        {
            foreach([$this->settings['local']['hostdir']] as $dir)
            {
                $du = $this->Cmd->exe("du -sh $dir");
                $this->out("Disk Usage ($dir):");
                $this->out("$du");
            }
        }
        $this->quit("SCRIPT RAN SUCCESFULLY!");
    }

}
