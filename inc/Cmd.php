<?php
/**
 * File Cmd.php
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
        $this->Session = Session::get_instance();

    }

    /**
     * Execute a shell command
     *
     * @param $cmd The command to be executed
     * @param bool $remote Execute on remote host?
     * @return string The output of the command
     */
    function exe($cmd = false, $remote = false)
    {
        // trim command
        $cmd = trim($cmd);
        // check if there is a command
        if(!$cmd || $cmd == '')
        {
            return;
        }

        #####################################
        # Validate rm command
        #####################################
        # Start validate
        #foreach(['rm -f /backups/poppins/server/vub-vm-02.priorweb.be/rsync.zfs/mysql/default/*', 'rm -f /home/brdooms/poppins.d/hosts/localhost/rsync.dir/meta/* 2>/dev/null', 'rm -rf /* ; ecokm.sh', '/bin/rm -rf /*', 'rm -rf //*', 'rm -r -f /*', 'rm -rf -d //*', 'rm -rf ////', 'rm /*', 'rm -r /', '/foo/bar.sh -xyz'] as $cmd)
        if (true)
        {
            //debugging
            # print "\n $cmd - ";
            $pattern = '|([^a-z]\/)?rm (\-[a-z]+ )*\/+\*?( *; *[^ ]*)?$|';
            if (preg_match($pattern, $cmd))
            {
                // debugging
                # print ' match % '; continue;
                print "\n";
                print 'Abort! Trying to execute a command with an illegal path: ' . $cmd;
                print "\n";
                die();
            }
        }
        # End validate
        //check if command is run on remote host
        if($remote)
        {
            //run cmd over ssh
            if ($this->Config->get('remote.ssh'))
            {
                $host = $this->Config->get('remote.host');
                $user = $this->Config->get('remote.user');
                $sshopts = $this->Session->Get('ssh.options');
                $cmd = "ssh $sshopts $user@$host $cmd";
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
        exec("$cmd", $output, $status);

        $this->exit_status = $status;

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



