<?php

class Application
{
    public $Cmd;
    
    private $messages;
    
    public $start_time;
    
    public $settings;
    
    private $version;
    
            
    function __construct($name, $version)
    {
        $this->start_time = date('U');
        
        $this->name = $name;
        
        $this->version = $version;
    }
    
    function fail($message, $error = '')
    {
        $this->log("FATAL ERROR: Application failed!");
        $this->log("MESSAGE: '$message'");
        $this->quit("SCRIPT FAILED!", $error);
    }
    
    function init()
    {
        #####################################
        # SIGNATURE
        #####################################
        $this->out("$this->name v$this->version - SCRIPT STARTED ".date('Y-m-d H:i:s', $this->start_time), 'title');
        $this->out('Validate local environment...', 'header');
        #####################################
        # CHECK OS
        #####################################
        $this->out('Validate local operating system...');
        $OS = trim(shell_exec('uname'));
        if(!in_array($OS, ['Linux', 'SunOS']))
        {
            $this->fail('Local OS '.$OS.' currently not supported!');
        }
        #####################################
        # USER
        #####################################
        $this->out('Validate local user...');
        $whoami = trim(shell_exec('whoami'));
        if ($whoami != "root")
        {
            $this->fail("You must run this script as root or else the permissions in the snapshot will be wrong.");
        }
        #####################################
        # HELP
        #####################################
        $options = getopt("c:h:");
        if (!count($options) || @$argv[1] == '--help')
        {
            die("Usage: " . $_SERVER['PHP_SELF'] . " -c {configfile} [-h {host}]\n");
        }
        #####################################
        # VALIDATE CONFIG FILE
        #####################################
        $this->out("Validate configuration file...", 'header');
        //validate config file
        if (isset($options['c']))
        {
            $configfile = $options['c'];
            if (!file_exists($configfile))
            {
                $this->fail("Config file not found!");
            }
            else
            {
                $this->settings = parse_ini_file($configfile, 1);
                $this->settings['local']['os'] = $OS;
            }
        }
        else
        {
            $this->fail("Option -c {configfile} is required!");
        }
        #####################################
        # COMMANDS
        #####################################
        $Cmd = CmdFactory::create($this->settings);
        //load commands
        $this->Cmd = $Cmd;
        #####################################
        # REMOTE VARIABLES
        #####################################
        $this->out('Validate remote variables...');
        //validate user
        $this->settings['remote']['user'] = ( empty($this->settings['remote']['user'])) ? 'root' : $this->settings['remote']['user'];
        //validate host
        if (isset($options['h']))
        {
            if($this->settings['remote']['host'])
            {
                $this->fail("Option -h is set while host is not empty in ini file!");
            }
            else
            {
                $this->settings['remote']['host'] = $options['h'];
            }
        }
        //check if remote host is set
        if (!$this->settings['remote']['host'])
        {
            $this->fail("Remote host is empty! Update the ini file or use -h parameter!");
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
        $this->out('Validate ssh connection...');
        $sshtest = $this->Cmd->exe("ssh -o BatchMode=yes $_u@$_h echo OK");
        if(!$sshtest)
        {
            $this->fail("SSH login attempt failed at remote host $_u@$_h!");
        }
        //get remote os
        $this->settings['remote']['os'] = $this->Cmd->exe("ssh $_u@$_h uname");
        //get distro
        foreach(['Debian', 'Ubuntu', 'SunOS', 'OpenIndiana', 'Red Hat', 'CentOS', 'Fedora'] as $d)
        {
            if(preg_match("/$d/i", $this->Cmd->exe("ssh $_u@$_h 'cat /etc/*release'")))
            {
                $this->settings['remote']['distro'] = $d;
                break;
            }
        }
        #####################################
        # SNAPSHOT DIR
        #####################################
        $this->out('Validate snapshot dir...');
        //validate dir
        $d = $this->settings['local']['snapshotdir'].'/'.$this->settings['remote']['host'];
        //to avoid confusion, an absolute path is required
        if(!preg_match('/^\//', $d))
        {
            $this->fail("Snapshotdir must be an absolute path!");
        }
        //check if dir exists
        if (!file_exists($d))
        {
            $this->fail("Snapshotdir " . $d . " does not exist!");
        }
        else
        {
            $this->settings['local']['snapshotdir'] = $d;
        }
        #####################################
        # SYNC AND PERIODIC DIRS
        #####################################
        $this->out('Validate snapshot subdirectories...');
        //validate dir
        foreach(['incremental', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'] as $d)
        {
            $dd = $this->settings['local']['snapshotdir'].'/'.$d;
            if (!is_dir($dd))
            {
               $this->out('Create subdirectory '.$dd.'...'); 
               $this->Cmd->exe("mkdir ".$dd, 'passthru');
            }
        }
        #####################################
        # LOGFILE DIR
        #####################################
        $this->out('Validate logfile dir...');
        //to avoid confusion, an absolute path is required
        if(!preg_match('/^\//', $this->settings['local']['logfiledir']))
        {
            $this->fail("Logfiledir must be an absolute path!");
        }
        //validate dir
        if (!file_exists($this->settings['local']['logfiledir']))
        {
            $this->fail("Logfiledir " . $this->settings['local']['logfiledir'] . " does not exist!");
        }
        //logfile
        $this->settings['local']['logfile'] = $this->settings['local']['logfiledir'].'/'.$this->settings['remote']['host'].'.'.date('Y-m-d.H-i-s', $this->start_time).'.poppins.log';
        $this->out('Create logfile '.$this->settings['local']['logfile'].'...');
        $this->Cmd->exe("touch ".$this->settings['local']['logfile'], 'passthru');
        #####################################
        # DUMP ALL SETTINGS
        #####################################
        $this->out('LIST CONFIGURATION...', 'header');
        $output = [];
        ksort($this->settings);
        foreach($this->settings as $k => $v)
        {
            if(is_array($v))
            {
                ksort($v);
                $output []= "\n[$k]";
                foreach($v as $kk => $vv)
                {
                    $vv = ($vv)? $vv:'""';
                    $output []= sprintf( "%s: %s" , $kk , $vv);
                }
            }
            else
            {
                $v = ($v)? $v:'""';
                $output []= sprintf( "%s: %s" , $k , $v);
            }
        }
        $this->out(trim(implode("\n", $output)));
    }

    function log($message)
    {
        $this->messages []= $message;
    }
    
    function out($message, $type = 'default')
    {
        $content = [];
        switch($type)
        {
            case 'title':
                $content []= "=======================================================================================";
                $content []= $message;
                $content []= "=======================================================================================";
                break;
            case 'header':
                $content []= "---------------------------------------------------------------------------------------";
                $content []= strtoupper($message);
                $content []= "---------------------------------------------------------------------------------------";
                break;
            case 'default':
                $content []= $message;
                break;
        }
        $message = implode("\n", $content);
        //log to file
        $this->log($message);
        //print to screen
        //print $message."\n";
    }
    
    function quit($message = '', $error = '')
    {
        $this->out("$this->name v$this->version - SCRIPT ENDED ".date('Y-m-d H:i:s', $this->start_time), 'title');
        //log message
        if($message)
        {
            $this->log($message);
        }
        //time script
        $lapse = date('U')-$this->start_time;
        if(true)
        {
            $lapse = gmdate('H:i:s', $lapse);
            $this->log("Script time (HH:MM:SS) : $lapse");
        }
        //remove LOCK file if exists
        if($error != 'LOCKED' && file_exists($this->settings['local']['snapshotdir']."/LOCK"))
        {
            $this->log("Remove LOCK file...");
            $this->Cmd->exe('{RM} '.$this->settings['local']['snapshotdir']."/LOCK", 'passthru', true);
        }
        //format messages
        $messages = implode("\n", $this->messages);
        //content
        $content = [];
        $content []= $messages;
        //log to file
        if($this->settings['local']['logfile'])
        {
            $content []= 'Write to log file...';
            $success = file_put_contents($this->settings['local']['logfile'], $messages);
            if(!$success)
            {
                $content []= 'FAILED!';
            }
            else
            {
                 $content []= 'OK';
            }
        }
        $content []= "Bye...";
        //last newline
        $content []= "";
        //write to log file
        //output
        print implode("\n", $content);
        die();
    }
    
    function succeed()
    {
        $this->log("SCRIPT RAN SUCCESFULLY!");
        $this->quit();
    }
}
