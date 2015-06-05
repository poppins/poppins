<?php

class Settings
{

    private $settings;

    function __construct($application, $commands)
    {
        //Application class
        $this->App = $application;
        
        //Commands class
        $this->Cmd = $commands;
    }

    function init()
    {
        #####################################
        # CLI OPTIONS
        #####################################
        $options = getopt("f:");
        if (!count($options))
        {
            $this->App->fail("Usage: " . $_SERVER['PHP_SELF'] . " -f [configfile]");
        }
        //validate cli options
        if (isset($options['f']))
        {
            $configfile = $options['f'];
            if (!file_exists($configfile))
            {
                $this->App->fail("Config file not found!");
            }
            else
            {
                $this->settings = parse_ini_file($configfile, 1);
            }
        }
        else
        {
            $this->App->fail("Option -f [configfile] is required!");
        }
        $this->App->out("Validate configuration file ($configfile)...", 'header');
        #####################################
        # REMOTE SETTINGS
        #####################################
        $this->App->out('Validate remote variables...');
        $this->settings['remote']['user'] = ( empty($this->settings['remote']['user'])) ? 'root' : $this->settings['remote']['user'];
        if (!$this->settings['remote']['host'])
        {
            $this->App->fail("Error in ini file. Remote host variable empty!");
        }
        else
        {
            $_h = $this->settings['remote']['host'];
            $_u = $this->settings['remote']['user'];
            $ping = $this->Cmd->exe("ping -c 1 $_h > /dev/null 2>&1 && echo OK || false");
            if (!$ping)
            {
                $this->App->fail("Cannot reach remote host $_u@$_h!");
            }
        }
        $this->App->out('Validate ssh connection...');
        $sshtest = $this->Cmd->exe("ssh -o BatchMode=yes $_u@$_h echo OK");
        if(!$sshtest)
        {
            $this->App->fail("SSH login attempt failed at remote host $_u@$_h!");
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
        $this->App->out('Validate snapshot dir...');
        //validate dir
        if (!file_exists($this->settings['local']['snapshotdir']))
        {
            $this->App->fail("Snapshotdir " . $this->settings['local']['snapshotdir'] . " does not exist!");
        }
        #####################################
        # SYNC AND PERIODIC DIRS
        #####################################
        $this->App->out('Validate snapshot subdirectories...');
        //validate dir
        foreach(['incremental', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'] as $d)
        {
            $dd = $this->settings['local']['snapshotdir'].'/'.$d;
            if (!is_dir($dd))
            {
               $this->App->out('Create subdirectory '.$dd.'...'); 
               $this->Cmd->exe("mkdir ".$dd, 'passthru');
            }
        }
        #####################################
        # LOGFILE DIR
        #####################################
        $this->App->out('Validate logfile dir...');
        //validate dir
        if (!file_exists($this->settings['local']['logfiledir']))
        {
            $this->App->fail("Logfiledir " . $this->settings['local']['logfiledir'] . " does not exist!");
        }
        //logfile
        $this->settings['local']['logfile'] = $this->settings['local']['logfiledir'].'/'.$this->settings['remote']['host'].'.'.date('Y-m-d.H-i-s', $this->App->start_time).'.poppins.log';
        $this->App->out('Create logfile '.$this->settings['local']['logfile'].'...');
        $this->Cmd->exe("touch ".$this->settings['local']['logfile'], 'passthru');
        #####################################
        # DUMP ALL SETTINGS
        #####################################
        $this->App->out('LIST CONFIGURATION...', 'header');
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
        $this->App->out(implode("\n", $output));
    }

    function get()
    {
        return $this->settings;
    }

}
