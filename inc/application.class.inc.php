<?php

class Application
{
    private $config;

    private $version;

    public $intervals;

    public $settings;

    public $Cmd;

    public $output_colors;

    private $messages;

    private $options;

    public $start_time;

    private $errors = [];

    private $warnings = [];

    function __construct($appname, $version)
    {
        #####################################
        # CONFIGURATION
        #####################################
        //colored output - not enabled by default, see --color option
        $this->config['colors'] = false;
        // intervals
        $this->intervals = ['incremental', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'];
        // app name
        $this->appname = $appname;
        // version
        $this->version = $version;

        // set start time
        $this->start_time = date('U');
    }

    function colorize($string, $fgcolor = 'white', $bgcolor = false)
    {
        //colorize the string
        if($this->config['colors'])
        {
            // foreground
            $fgcolors['black'] = '0;30';
            $fgcolors['dark_gray'] = '1;30';
            $fgcolors['blue'] = '0;34';
            $fgcolors['light_blue'] = '1;34';
            $fgcolors['green'] = '0;32';
            $fgcolors['light_green'] = '1;32';
            $fgcolors['cyan'] = '0;36';
            $fgcolors['light_cyan'] = '1;36';
            $fgcolors['red'] = '0;31';
            $fgcolors['light_red'] = '1;31';
            $fgcolors['purple'] = '0;35';
            $fgcolors['light_purple'] = '1;35';
            $fgcolors['brown'] = '0;33';
            $fgcolors['yellow'] = '1;33';
            $fgcolors['light_gray'] = '0;37';
            $fgcolors['white'] = '1;37';
            //background
            $bgcolors['black'] = '40';
            $bgcolors['red'] = '41';
            $bgcolors['green'] = '42';
            $bgcolors['yellow'] = '43';
            $bgcolors['blue'] = '44';
            $bgcolors['magenta'] = '45';
            $bgcolors['cyan'] = '46';
            $bgcolors['light_gray'] = '47';
            //return string
            $colored_string = '';
            // set foreground
            if (isset($fgcolors[$fgcolor]))
            {
                $colored_string .= "\033[" . $fgcolors[$fgcolor] . "m";
            }
            // set background
            if (isset($bgcolors[$bgcolor]))
            {
                $colored_string .= "\033[" . $bgcolors[$bgcolor] . "m";
            }
            $colored_string .=  $string . "\033[0m";
            return $colored_string;
        }
        else
        {
            return $string;
        }
    }

    function fail($message = '', $error = 'generic')
    {
        $this->errors []= $message;
        //compose message
        $output = [];
        $output []= "$message";
        $this->out(implode("\n", $output), 'error');
        //quit
        $this->quit($error);
    }

    function init()
    {
        #####################################
        # HELP
        #####################################\
        $CLI_SHORT_OPTS = ["c:dhv"];
        $CLI_LONG_OPTS = ["version", "help", "color"];
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
        // colored output
        if (isset($this->options['color']))
        {
            $this->config['colors'] = true;
        }
        #####################################
        # START
        #####################################
        $this->out("$this->appname v$this->version - SCRIPT STARTED " . date('Y-m-d H:i:s', $this->start_time), 'title');
        $this->out('local environment', 'header');
        #####################################
        # VALIDATE OS
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
                        $this->warn('Config values may not contain spaces. Value for '.$kk.' ['.$k.'] is trimmed!');
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
        # VALIDATE LOG DIR
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
        # VALIDATE REMOTE PARAMS
        #####################################
        $this->out('Check remote parameters...');
        //validate user
        $this->settings['remote']['user'] = ( empty($this->settings['remote']['user'])) ? $this->Cmd->exe('whoami') : $this->settings['remote']['user'];
        //check remote host
        if (empty($this->settings['remote']['host']))
        {
            $this->fail("Remote host is not configured!! Specify it in the ini file or on the command line!");
        }
        $this->out('Check ssh connection...');
        //obviously try ssh at least once :)
        $attempts = 1;
        //retry attempts on connection fail
        if (isset($this->settings['connection']['retry-count']))
        {
          $attempts += (integer) $this->settings['connection']['retry-count'];
        }
        //allow for a timeout
        $timeout = 0;
        if (isset($this->settings['connection']['retry-timeout']))
        {
            $timeout += (integer) $this->settings['connection']['retry-timeout'];
        }
        $i = 1;
        $success = false;
        $_h = $this->settings['remote']['host'];
        $_u = $this->settings['remote']['user'];
        while ($i <= $attempts)
        {
            $this->Cmd->exe("ssh -o BatchMode=yes $_u@$_h echo OK");
            if ($this->Cmd->is_error())
            {
              $this->warn("SSH connection failed!");
              if ($i != $attempts)
              {
                  $this->out("Will retry ssh attempt " . ($i + 1) . " of $attempts in $timeout second(s)...\n");
                  sleep($timeout);
              }
              $i++;
            }
            else
            {
              $success = true;
              break;
            }
        }
        //check if successful
        if (!$success)
        {
          $this->fail("SSH login attempt failed at remote host $_u@$_h! \nGenerate a key with ssh-keygen and ssh-copy-id to set up a passwordless ssh connection?");
        }
        //get remote os
        $this->settings['remote']['os'] = $this->Cmd->exe("ssh $_u@$_h uname");
        //get distro
        // try /etc/*release
        $output = $this->Cmd->exe("ssh $_u@$_h 'cat /etc/*release'");
        if ($this->Cmd->is_error())
        {
            $output = $this->Cmd->exe("ssh $_u@$_h 'lsb_release -a'");
            if ($this->Cmd->is_error())
            {
                 $this->fail('Cannot discover remote distro!');
            }
        }
        foreach (['Ubuntu', 'Debian', 'SunOS', 'OpenIndiana', 'Red Hat', 'CentOS', 'Fedora', 'Manjaro', 'Arch'] as $d)
        {
            if (preg_match("/$d/i", $output))
            {
                $this->settings['remote']['distro'] = $d;
                break;
            }
        }
        //check pre backup script
        if(!array_key_exists('pre-backup-script', $this->settings['remote']))
        {
            $this->warn('Directive pre-backup-script [remote] is not configured!');
        }
        //it exists, check onfail action
        elseif($this->settings['remote']['pre-backup-script'])
        {
            //check if path is set correctly
            if (!preg_match('/^\//',  $this->settings['remote']['pre-backup-script']))
            {
                $this->fail("pre-backup-script must be an absolute path!");
            }
            //check if action is set correctly
            if(!in_array($this->settings['remote']['pre-backup-onfail'], ['abort', 'continue']))
            {
                $this->fail('Wrong value for "pre-backup-onfail". Use "abort" or "continue"!');
            }
        }
        #####################################
        # VALIDATE DEPENDENCIES
        #####################################
        $this->out('Check dependencies...');
        $dependencies = [];
        //Debian - Ubuntu
        if(in_array($this->settings['remote']['distro'], ['Debian', 'Ubuntu']))
        {
//            $dependencies['remote']['aptitude'] = 'aptitude --version';
        }
        //Red Hat - Fedora
        if(in_array($this->settings['remote']['distro'], ['Red Hat', 'CentOS', 'Fedora']))
        {
            //yum is nice though rpm will suffice, no hard dependency needed
            //$dependencies['remote']['yum-utils'] = 'yumdb --version';
        }
        //Arch - Manjaro
        if(in_array($this->settings['remote']['distro'], ['Arch', 'Manjaro']))
        {
            $dependencies['remote']['pacman'] = 'pacman --version';
        }
        $dependencies['remote']['rsync'] = 'rsync --version';
        //local
        $dependencies['local']['gzip'] = 'gzip --version';
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
        # VALIDATE ROOT DIR & FILE SYSTEM
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
        # VALIDATE HOST DIR
        #####################################
        $this->out('Check host...');
        $this->settings['local']['hostdir-name'] = ($this->settings['local']['hostdir-name'])? $this->settings['local']['hostdir-name']:$this->settings['remote']['host'];
        //check if absolute path
        if (preg_match('/^\//', $this->settings['local']['hostdir-name']))
        {
            $this->fail("hostname may not contain slashes!");
        }
        $this->settings['local']['hostdir'] = $this->settings['local']['rootdir'] . '/' . $this->settings['local']['hostdir-name'];
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
        # VALIDATE RSYNC SETTINGS
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
        //retry options must be integers
        $options = ['retry-count', 'retry-timeout'];
        foreach($options as $o)
        {
            if(isset($this->settings['rsync'][$o]))
            {
                //must be a number
                 if(!preg_match("/^[0-9]+$/", $this->settings['rsync'][$o]))
                 {
                     $this->fail("Illegal value for '$o' [rsync]. Not an integer!");
                 }
            }
        }
        #####################################
        # BASIC DIRECTIVE VALIDATION
        #####################################
        $validate = [];
        //required directives - give an error
        $sections = [];
        $sections ['local'] = ['rootdir', 'logdir', 'hostdir-name', 'hostdir-create', 'filesystem'];
        $sections ['remote'] = ['host', 'user'];
        $sections ['mysql'] = ['enabled'];
        $validate['error'] = $sections;
        //absent directives - give a warning
        $sections = [];
        $sections ['remote'] = ['pre-backup-script', 'pre-backup-onfail'];
        $sections ['rsync'] = ['compresslevel', 'hardlinks', 'verbose', 'retry-count', 'retry-timeout'];
        $sections ['meta'] = ['remote-disk-layout', 'remote-package-list'];
        $sections ['mysql'] = ['configdirs'];
        $sections ['log'] = ['local-disk-usage', 'compress'];
        $validate['warning'] = $sections;
        foreach($validate as $onfail => $sections)
        {
            foreach($sections as $section => $directives)
            {
                foreach($directives as $d)
                {
                    if(@!is_array($this->settings[$section]) || @!array_key_exists($d, $this->settings[$section]))
                    {
                        if($onfail == 'error')
                        {
                            $this->fail('Directive '.$d.' ['.$section.'] is not configured!');
                        }
                        else
                        {
                            $this->warn('Directive '.$d.' ['.$section.'] is not configured!');
                        }
                    }
                }
            }
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

    function log($message = '', $fgcolor = false, $bgcolor = false)
    {
        $this->messages [] = $message;
        //output color
        if($fgcolor) $this->output_colors [count($this->messages) - 1] = [$fgcolor, $bgcolor];
    }

    function out($message = '', $type = 'default')
    {
        $content = [];
        $fgcolor = false;
        $bgcolor = false;
        switch ($type)
        {
            case 'error':
                $fgcolor = 'light_red';
                $l1 = '$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$ FATAL ERROR $$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$';
                $l2 = '$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$';
                $content [] = '';
                $content [] = $l1;
                $content [] = wordwrap($message, 80);
                $content [] = $l2;
                $content [] = '';
                break;
            case 'final-error':
                $fgcolor = 'light_red';
                $content [] = '';
                $content [] = $message;
                $content [] = '';
                break;
            case 'final-success':
                $fgcolor = 'green';
                $content [] = '';
                $content [] = $message;
                $content [] = '';
                break;
            case 'header':
                $l = "-----------------------------------------------------------------------------------------";
                $content [] = $l;
                $content [] = strtoupper($message);
                $content [] = $l;
                break;
            case 'indent':
                $fgcolor = 'cyan';
                $content [] = "-----------> " . $message;
                break;
            case 'indent-error':
                $fgcolor = 'light_red';
                $content [] = "-----------> " . $message;
                break;
            case 'indent-warning':
                $fgcolor = 'brown';
                $content [] = "-----------> " . $message;
                break;
            case 'simple-error':
                $fgcolor = 'light_red';
                $content [] = $message;
                break;
            case 'simple-info':
                $fgcolor = 'cyan';
                $content [] = $message;
                break;
            case 'simple-warning':
                $fgcolor = 'brown';
                $content [] = $message;
                break;
            case 'simple-success':
                $fgcolor = 'green';
                $content [] = $message;
                break;
            case 'title':
                $l = "%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%";
                $content [] = $l;
                $content [] = $message;
                $content [] = $l;
                break;
            case 'warning':
                $fgcolor = 'brown';
                $l1 = '|||||||||||||||||||||||||||||||||||| WARNING |||||||||||||||||||||||||||||||||||||||||||||';
                $l2 = '||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||';
                $content [] = '';
                $content [] = $l1;
                $content [] = wordwrap($message, 85);
                $content [] = $l2;
                $content [] = '';
                break;
            case 'default':
                $content [] = $message;
                break;
        }
        $message = implode("\n", $content);
        //log to file
        $this->log($message, $fgcolor, $bgcolor);
    }

    function abort($message = '')
    {
        if($message)
        {
            $message .= "\n";
        }
        die($message);
    }

    function quit($error = false)
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
        //title
        $this->out('SUMMARY', 'header');
        //report warnings
        $warnings = count($this->warnings);
        //output all warnings
        if($warnings)
        {
           $this->out("WARNINGS (".$warnings.")", 'simple-warning');
            foreach($this->warnings as $w)
            {
                $this->out($w, 'indent-warning');
            }
            $this->out();
        }
        //report errors
        $errors = count($this->errors);
        if($errors)
        {
           $this->out("ERRORS (".$errors.")", 'simple-error');
            foreach($this->errors as $e)
            {
                $this->out($e, 'indent-error');
            }
            $this->out();
        }
        //log message
        if(!$error)
        {
            $this->out("SCRIPT RAN SUCCESSFULLY!", 'final-success');
        }
        else
        {
            $this->out("SCRIPT FAILED!", 'final-error');

        }
        //time script
        $lapse = date('U') - $this->start_time;
        $lapse = gmdate('H:i:s', $lapse);
        $this->log("Script time: $lapse (HH:MM:SS)");
        //final header
        $this->out("$this->appname v$this->version - SCRIPT ENDED " . date('Y-m-d H:i:s'), 'title');
        #####################################
        # OUTPUT
        #####################################
        //colorize output
        $content = [];
        $i = 0;
        foreach($this->messages as $m)
        {
            if(@isset($this->output_colors[$i]))
            {
                $content []= $this->colorize($m, $this->output_colors[$i][0], $this->output_colors[$i][1]);
            }
            else
            {
                $content []= $m;
            }
            $i++;
        }
        #####################################
        # CLEANUP
        #####################################
        //remove LOCK file if exists
        if ($error != 'LOCKED' && file_exists(@$this->settings['local']['hostdir'] . "/LOCK"))
        {
            $content [] = "Remove LOCK file...";
            $this->Cmd->exe('{RM} ' . $this->settings['local']['hostdir'] . "/LOCK");
        }
        #####################################
        # WRITE LOG TO FILES
        #####################################
        //write to log
        if (is_dir($this->settings['local']['logdir']))
        {
            if (!empty($this->settings['remote']['host']))
            {
                //output
                if($error)
                {
                    $result = 'error';
                }
                else
                {
                    $result = (count($this->warnings))? 'warning':'success';
                }
                
                $hostdirname = ($this->settings['local']['hostdir-name'])? $this->settings['local']['hostdir-name']:$this->settings['remote']['host'];
                $logfile_host = $this->settings['local']['logdir'] . '/' . $hostdirname . '.' . date('Y-m-d_His', $this->start_time) . '.poppins.' . $result. '.log';
                $logfile_app = $this->settings['local']['logdir'] . '/poppins.log';
                $content [] = 'Create logfile for host ' . $logfile_host . '...';
                //create file
                $this->Cmd->exe("touch " . $logfile_host);

                if ($this->Cmd->is_error())
                {
                    $content []= 'WARNING! Cannot write to host logfile. Cannot create log file!';
                }
                else
                {
                    $success = file_put_contents($logfile_host, implode($this->messages, "\n")."\n");
                    if (!$success)
                    {
                        $content []= 'WARNING! Cannot write to host logfile. Write protected?';
                    }
                    //write to application log
                    $this->Cmd->exe("touch " . $logfile_app);
                    if ($this->Cmd->is_error())
                    {
                        $content [] = 'WARNING! Cannot write to application logfile. Cannot create log file!';
                    }
                    $m = [];
                    $m['timestamp'] = date('Y-m-d H:i:s');
                    $m['host'] = $hostdirname;
                    $m['result'] = strtoupper($result);
                    $m['lapse'] = $lapse;
                    $m['logfile'] = $logfile_host;
                    //compress host logfile?
                    if(isset($this->settings['log']['compress']) && $this->settings['log']['compress'])
                    {
                        $content [] = 'Compress log file...';
                        $this->Cmd->exe("gzip " . $logfile_host);
                        //append suffix in log
                        $m['logfile'] .= '.gz';
                    }
                    $m['version'] = $this->version;
                    //$m['error'] = $error;
                    foreach($m as $k => $v)
                    {
                        $m[$k] = '"'.$v.'"';
                    }
                    $message = implode(' ', array_values($m))."\n";
                    $content [] = 'Add "'.$result.'" to logfile ' . $logfile_app . '...';
                    $success = file_put_contents($logfile_app, $message, FILE_APPEND | LOCK_EX);
                    if (!$success)
                    {
                        $content []= 'WARNING! Cannot write to application logfile. Write protected?';
                    }
                }
            }
            else
            {
                $content []= 'WARNING! Cannot write to host logfile. Remote host not specified!';
            }
        }
        else
        {
            $content []= 'WARNING! Cannot write to logfile. Log directory not created!';
        }
        //be polite
        $content [] = "Bye...!";
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
        $this->quit();
    }

    function warn($message)
    {
        $this->warnings []= $message;
        $this->out($message, $type = 'warning');
    }

}
