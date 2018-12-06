<?php
/**
 * File rotator.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Rotator contains functions that will handle rotation based on hardlinks,
 * zfs or btrfs snapshots
 */
class Rotator
{
    // Application class
    protected $App;

    // Config class
    protected $Config;

    // Options class - options passed by getopt and ini file
    protected $Options;

    // Settings class - application specific settings
    protected $Settings;

    /**
     * Rotator constructor.
     * @param $App Application class
     */
    function __construct($App)
    {
        $this->App = $App;

        $this->Cmd = $App->Cmd;
        #####################################
        # CONFIGURATION
        #####################################
        //Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // App specific settings
        $this->Session = Session::get_instance();

        //create datestamp
        $this->Session->set('rsync.cdatestamp', date('Y-m-d_His', $this->Session->get('chrono.session.start')));

        //directories
        $this->archivedir = $this->Config->get('local.hostdir') . '/archive';
        
        $this->newdir = $this->Config->get('local.hostdir-name') . '.' . $this->Session->get('rsync.cdatestamp') . '.poppins';

        $this->rsyncdir = $this->Config->get('local.rsyncdir');
                
        $this->dir_regex = '[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}';
    }

    /**
     * Initialise the class
     * Sort data
     * Compare datestamps
     * Check archives
     */
    function init()
    {
        $this->App->out('Rotate snapshots', 'header');
        //dry run?
        if($this->Options->is_set('n'))
        {
            $this->App->out('DRY RUN!');
            return;
        }
        // mark time
        $this->Session->set('chrono.rotation.start', date('U'));
        //prepare
        $this->prepare();
        //iniate comparison
        $this->App->out('Scan archives...');
        $arch1 = $arch2 = $this->scandir();
        #####################################
        # SORT DATA 
        #####################################
        foreach ($arch2 as $k => $v)
        {
            //initiate base and end
            $base[$k] = '';
            $end[$k] = '';
            //set if archives
            if (count($arch2[$k]))
            {
                asort($arch2[$k]);
                $base[$k] = $arch2[$k][0];
                $end[$k] = (end($arch2[$k]));
            }
        }
        #####################################
        # COMPARE DATESTAMPS
        #####################################
        foreach ($arch2 as $k => $v)
        {
            
            //no datestamp comparison needed
            if ($k == 'incremental')
            {
                $arch2[$k] [] = $this->newdir;
            }
            //datestamp comparison
            else
            {
                if ($end[$k])
                {
                    //validate
                    if (!preg_match("/$this->dir_regex/", $end[$k], $m))
                    {
                        $this->App->fail("Wrong dirstamp format found, cannot continue!");
                    }
                    else
                    {
                        //directory timestamp
                        $ddatestamp = $m[0];
                        //convert to seconds
                        $cdatestamp2unix = $this->to_time($this->Session->get('rsync.cdatestamp'));
                        $ddatestamp2unix = $this->to_time($ddatestamp);
                        //in theory this is not possible, check it anyway - testing purposes
                        if ($cdatestamp2unix < $ddatestamp2unix)
                        {
                            $this->App->fail('Cannot continue. Newer dir found: ' . $ddatestamp);
                        }
                        else
                        {
                            $diff = $cdatestamp2unix - $ddatestamp2unix;
                            if ($this->time_exceed($diff, $k))
                            {
                                $arch2[$k] [] = $this->newdir;
                            }
                        }
                    }
                }
                else
                {
                    $arch2[$k] [] = $this->newdir;
                }
            }
        }
        #####################################
        # SLICE
        #####################################
        //check how many dirs are desired 
        foreach ($arch2 as $k => $v)
        {
            $n = $this->Config->get(['snapshots', $k]);
            $arch2[$k] = array_slice($arch2[$k], -$n, $n);
        }
        #####################################
        # COMPARE ARCHIVES TO RESULT
        #####################################
        $this->App->out("Rotate snapshots...");
        //add/remove
        $actions = [];
        $actions['add'] = [];
        $actions['remove'] = [];
        //rotation
        foreach ($arch2 as $k => $v)
        {
            if (count($v))
            {
                foreach ($v as $vv)
                {
                    $actions['remove'][$k] = array_diff($arch1[$k], $arch2[$k]);
                    $actions['add'][$k] = array_diff($arch2[$k], $arch1[$k]);
                }
            }
            else
            {
                $actions['remove'][$k] = $arch1[$k];
            }
        }
        //determine actions to take 
        foreach (array_keys($actions) as $action)
        {
            if (count($actions[$action]))
            {
                foreach ($actions[$action] as $k => $v)
                {
                    if (count($v))
                    {
                        foreach ($v as $vv)
                        {
                            switch ($action)
                            {
                                case 'add':
                                    $message = "Add $vv to $k...";
                                    break;
                                case 'remove':
                                    $message = "Remove $vv from $k...";
                                    break;
                            }
                            $this->App->out($message, 'indent');
                            $this->$action($vv, $k);
                            //check if command returned ok
                            if($this->Cmd->is_error())
                            {
                                $this->App->fail('Cannot rotate. Command failed!');
                            }
                        }
                    }
                }
            }
        }
        //final housekeeping if needed
        $this->finalize();
        // done
        $this->App->out();
        $this->App->out("OK!", 'simple-success');
        #####################################
        # LIST ARCHIVES
        #####################################
        $this->App->out('List snapshots ('.$this->Config->get('local.snapshot-backend').')', 'header');
        $this->App->out('Check archive directory...');
        $res = $this->scandir(true);
        $this->App->out('List snapshots...');
        $this->App->out();
        foreach($res as $k =>$v)
        {
            $this->App->out($k);
            // sort reverse order
            krsort($v);
            foreach($v as $vv)
            {
                $this->App->out($vv, 'indent');
            }
        }
        // mark time
        $this->Session->set('chrono.rotation.stop', date('U'));
    }

    /**
     * Wrap up the action
     */
    function finalize()
    {
	    return;
    }

    /**
     * Prepare the rotation
     * Check archive dir
     */
    function prepare()
    {
        #####################################
        # CHECK ARCHIVE DIR
        #####################################
        $this->App->out('Check hostdir subdirectories...');
        //validate dir
        foreach (['archive'] as $d)
        {
            $dd = $this->Config->get('local.hostdir') . '/' . $d;
            if (!is_dir($dd))
            {
                $this->App->out('Create subdirectory ' . $dd . '...');
                $this->Cmd->exe("mkdir -p " . $dd);
            }
        }
        #####################################
        # ARCHIVES
        #####################################
        $this->App->out('Check archive subdirectories...');
        //validate dir
        foreach (array_keys($this->Config->get('snapshots')) as $d)
        {
            $dd = $this->archivedir . '/' . $d;
            if (!is_dir($dd))
            {
                $this->App->out('Create subdirectory ' . $dd . '...');
                $this->Cmd->exe("mkdir -p " . $dd);
            }
        }
    }

    /**
     * Function will scan for each subdirectory in /archive
     *
     * @param $validate Validate if unknown files or directories
     * @return array The directories
     */
    function scandir($validate = false)
    {
        //variables
        $scanned_dirs = [];
        $res = [];
        //archive dir
        $archivedir = $this->archivedir;

        // get the types e.g. incremental, 10-minutely, etc
        $snapshot_types = array_keys($this->Config->get('snapshots'));

        // build an array of directories
        foreach ($snapshot_types as $snapshot_type)
        {
            //keys must be stored in array!
            $res[$snapshot_type] = [];
            $dir = $archivedir . '/' . $snapshot_type;
            if(is_dir($dir))
            {
                // get all snapshots from certain type e.g. incrementals
                $scanned_dirs[$snapshot_type] = scandir($dir);
            }
        }
        //create whitelist for validation
        $whitelist = [];
        //validate array
        foreach ($scanned_dirs as $snapshot_type => $snapshots)
        {
            //initiate variable
            $whitelist[$snapshot_type] = [];

            // iterate through all snapshots
            foreach ($snapshots as $snapshot)
            {
                //check if dir
                $prefix = str_replace('.', '\.', $this->Config->get('local.hostdir-name'));
                if (is_dir("$archivedir/$snapshot_type/$snapshot"))
                {
                    if (preg_match("/$prefix\.$this->dir_regex\.poppins$/", $snapshot))
                    {
                        // e.g. incremental => x, y, z
                        $res[$snapshot_type] [] = $snapshot;
                        // add to whitelist
                        $whitelist [$snapshot_type] [] = $snapshot;
                    }
                }
            }
        }

        // check if unclean
        if($validate)
        {

            $unclean_files = [];
            foreach ($snapshot_types as $snapshot_type)
            {
                //unclean files
                $allowed = $whitelist[$snapshot_type];
                $unclean_files = Validator::get_unclean_files("$archivedir/$snapshot_type", $allowed);

                if (count($unclean_files))
                {
                    foreach ($unclean_files as $file => $type)
                    {
                        $this->App->notice("Archive subdirectory $dir not clean, unknown $type '$file'. Remove or rename to '_$file'..");
                    }
                }
            }
        }
        return $res;
    }

    /**
     * This function will convert timestamps to other formats
     *
     * @param $stamp The timestamp
     * @param string $format The format: unix or date
     * @return string The formatted string
     */
    function to_time($stamp, $format = 'unix')
    {
        $t = explode('_', $stamp);
        $date = $t[0];
        $time = implode(':', str_split($t[1], 2));
        $datetime = $date . ' ' . $time;
        switch ($format)
        {
            case 'unix':
                $result = strtotime($datetime);
                break;
            case 'string':
                $result = date($datetime);
                break;
        }
        return $result;
    }

    /**
     * This function will check if an amount of seconds
     * exceeds a human readable value
     *
     * @param $diff The amount of seconds passed
     * @param $snapshot The snapshot directory, e.g. 1-daily
     * @return bool Check if time has exceeded or not
     */
    function time_exceed($diff, $snapshot)
    {
        //parse type 
        $a = explode('-', $snapshot);
        $offset = (integer) $a[0];
        $interval = $a[1];
        //validate
        if (!in_array($interval, $this->Session->get('intervals')))
        {
            $this->App->fail('Interval not supported!');
        }
        //check if integer, else fail!
        if (!is_integer($diff))
        {
            $this->App->fail('Cannot compare dates if no integer!');
        }
        
        //seconds
        $seconds['minutely'] = 60;
        $seconds['hourly'] = 60 * 60;
        $seconds['daily'] = $seconds['hourly'] * 24;
        $seconds['weekly'] = $seconds['daily'] * 7;
        $seconds['monthly'] = $seconds['daily'] * 30;
        $seconds['yearly'] = $seconds['daily'] * 365;
        return (boolean) ($diff >= ($seconds[$interval]*$offset));
    }

}

class DefaultRotator extends Rotator
{
    /**
     * Creates a command to add a snapshot dir to a parent directory
     *
     * @param $dir The snapshot directory
     * @param $parent The parent directory
     * @return string The command
     */
    function add($dir, $parent)
    {
        $cmd = "{CP} -la $this->rsyncdir ". $this->archivedir."/$parent/$dir";
        $this->App->out('Create hardlink copy: '.$this->Cmd->parse($cmd));
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Creates a command to remove a snapshot from a directory
     *
     * @param $dir The snapshot directory
     * @param $parent  The parent directory
     * @return string The command
     */
    function remove($dir, $parent)
    {
        // take precautions when executing an rm command!
        foreach([$this->archivedir, $dir, $parent] as $variable)
        {
            $variable = trim($variable);
            if (!$variable || empty($variable) || $variable == '' || preg_match('/^\/+$/', $variable))
            {
                $this->App->fail('Cannot execute a rm command as a variable is empty!');
            }
        }
        $cmd = "{RM} -rf ". $this->archivedir."/$parent/$dir";
        $this->App->out('Remove direcory: '.$this->Cmd->parse($cmd));
        return $this->Cmd->exe("$cmd");
    }
}

class BtrfsRotator extends Rotator
{
    /**
     * Creates a command to add a snapshot dir to a parent directory
     *
     * @param $dir The snapshot directory
     * @param $parent The parent directory
     * @return string The command
     */
    function add($dir, $parent)
    {
        $cmd = "btrfs subvolume snapshot -r $this->rsyncdir ". $this->archivedir."/$parent/$dir";
        $this->App->out("Create btrfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Creates a command to remove a snapshot from a directory
     *
     * @param $dir The snapshot directory
     * @param $parent  The parent directory
     * @return string The command
     */
    function remove($dir, $parent)
    {
        $cmd = "btrfs subvolume delete ". $this->archivedir."/$parent/$dir";
        $this->App->out("Remove btrfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }
}

class ZfsRotator extends Rotator
{
    /**
     * Creates a command to add a snapshot dir to a parent directory
     *
     * @param $dir The snapshot directory
     * @param $parent The parent directory
     * @return string The command
     */
    function add($dir, $parent)
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $cmd = "zfs snapshot $rsyncdir@$parent-$dir";
        $this->App->out("Create zfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Wrap up the action
     */
    function finalize()
    {
        //create a symlink to .zfs
        if(file_exists($this->rsyncdir.'/.zfs/snapshot') && !file_exists($this->Config->get('local.hostdir').'/archive'))
        {
            $this->App->out("Create an archive dir symlink to zfs snapshots...");
            $cmd = 'ln -s '.$this->rsyncdir.'/.zfs/snapshot '.$this->Config->get('local.hostdir').'/archive';
            $this->Cmd->exe("$cmd");

        }
    }

    /**
     * Creates a command to remove a snapshot from a directory
     *
     * @param $dir The snapshot directory
     * @param $parent  The parent directory
     * @return string The command
     */
    function remove($dir, $parent)
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $cmd = "zfs destroy $rsyncdir@$parent-$dir";
        $this->App->out("Remove zfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Prepare the rotation
     * Check archive dir
     */
    function prepare()
    {
        $this->App->out('No archive directories to create..');
    }

    /**
     * Function will scan for directories
     *
     * @param $validate Validate if unknown files or directories
     * @return array The directories
     */
    function scandir($validate = false)
    {
        //variables
        $res = [];
        //archive dir
        $archivedir = $this->rsyncdir.'/.zfs/snapshot';
        $snapshots = scandir($archivedir);
        //scan thru all intervals
        foreach (array_keys($this->Config->get('snapshots')) as $k)
        {
            //keys must be stored in array!
            $res[$k] = [];
            if(is_array($snapshots))
            {
                foreach($snapshots as $snapshot)
                {
                    if(preg_match('/^'.$k.'/', $snapshot))
                    {
                        $snap = str_replace($k.'-', '', $snapshot);
                        $prefix = str_replace('.', '\.', $this->Config->get('local.hostdir-name'));
                        if(preg_match("/$prefix\.$this->dir_regex\.poppins$/", $snap))
                        {
                            $res[$k][]= $snap;
                        }
                        elseif($validate)
                        {
                            //unclean files
                            $unclean_files = Validator::get_unclean_files($archivedir, $this->Config->get('snapshots'));
                            if (count($unclean_files))
                            {
                                // unclean file found
                                $this->App->notice("Snapshot directory $archivedir not clean, unknown snapshot $snapshot..");
                            }
                        }
                    }
                }
            }
        }
        return $res;
    }
}
