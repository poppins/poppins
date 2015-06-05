<?php
class Application
{
    public $Cmd;
    
    private $messages;
    
    public $OS;
    
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
        $this->out('Validate local settings...', 'header');
        #####################################
        # USER
        #####################################
        $this->out('Validate user...');
        $whoami = trim(shell_exec('whoami'));
        if ($whoami != "root")
        {
            $this->fail("You must run this script as root or else the permissions in the snapshot will be wrong.");
        }
        #####################################
        # OS
        #####################################
        $this->out('Validate local operating system...');
        $this->OS = trim(shell_exec('uname'));
        if(!in_array($this->OS, ['Linux', 'SunOS']))
        {
            $this->fail('Local OS '.$this->OS.' currently not supported!');
        }
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
