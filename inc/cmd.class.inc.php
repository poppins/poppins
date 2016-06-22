<?php
/**
 * File cmd.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class Cmd is used to execute shell commands locally or remotely. These may vary
 * according to a specific operating system.
 */
class Cmd
{

    public $commands = [];

    public $exit_code = [];
    
    public $output = '';

    // Config class
    protected $Config;

    // Options class - options passed by getopt and ini file
    protected $Options;

    // Settings class - application specific settings
    protected $Settings;

    /**
     * Cmd constructor.
     */
    function __construct()
    {
        $this->map = $this->map();

        #####################################
        # CONFIGURATION
        #####################################
        //Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // App specific settings
        $this->Settings = Settings::get_instance();

    }

    /**
     * Execute a shell command
     *
     * @param $cmd The command to be executed
     * @param bool $remote Execute on remote host?
     * @return string The output of the command
     */
    function exe($cmd, $remote = false)
    {
        //check if command is run on remote host
        if($remote)
        {
            //run cmd over ssh
            if ($this->Config->get('remote.ssh'))
            {
                $host = $this->Config->get('remote.host');
                $user = $this->Config->get('remote.user');
                $cmd = "ssh -o BatchMode=yes $user@$host $cmd";
            }
            // run on localhost (no ssh mode)
            else
            {
                $cmd = "eval $cmd";
            }
        }

        //check if parsing is needed
        foreach (array_keys($this->map) as $c)
        {
            if (preg_match('/' . $c . '/', $c))
            {
               $cmd = $this->parse($cmd);
            }
        }

        //store command
        $this->cmd = $cmd;
        $this->commands []= $cmd;
        
        //redirect error to standard
        $o = trim(exec("$cmd", $output, $this->exit_status));
        
        //output is an array, we want a string
        $this->output = implode("\n", $output);
               
        return $this->output;
    }

    /**
     * Returns if command executed successfully
     *
     * @return bool
     */
    public function is_error()
    {
        //if all is well, 0 is returned, else e.g. 127
        //we may consider to put other exit codes in the array besides 0
        return (boolean)(!in_array($this->exit_status, [0]));
    }

    /**
     * Parse the command. In some cases the command is a placeholder. Replace
     * it with the right command, according to operating system.
     *
     * @param $cmd The command to be executed
     * @return mixed The command
     */
    function parse($cmd)
    {
        $map = $this->map;
        $search = array_keys($map);
        $replace = array_values($map);
        $cmd = str_replace($search, $replace, $cmd);
        return $cmd;
    }

}

/**
 * Class LinuxCmd is used for all linux commands
 */
class LinuxCmd extends Cmd
{

    function map()
    {
        $cmd = [];
        $cmd['{CP}'] = 'cp';
        $cmd['{MV}'] = 'mv';
        $cmd['{RM}'] = 'rm';
        $cmd['{SSH}'] = 'ssh';
        $cmd['{GREP}'] = 'grep';
        $cmd['{DF}'] = 'df';
        return $cmd;
    }

}

/**
 * Class SunOSCmd is used for all SunOS commands
 */
class SunOSCmd extends Cmd
{

    function map()
    {
        $cmd = [];
        $cmd['{CP}'] = '/bin/cp';
        $cmd['{MV}'] = '/usr/bin/mv';
        $cmd['{RM}'] = '/usr/gnu/bin/rm';
        $cmd['{SSH}'] = '/opt/csw/bin/ssh';
        $cmd['{GREP}'] = '/usr/bin/ggrep';
        $cmd['{DF}'] = '/usr/gnu/bin/df';
        return $cmd;
    }

}
