<?php
/**
 * File Rotator.php
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

    // validate the archive dir
    protected $validate;

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
        $this->archive_dir = $this->Config->get('local.hostdir') . '/archive';

        $this->newdir = $this->Config->get('local.hostdir-name') . '.' . $this->Session->get('rsync.cdatestamp') . '.poppins';

        $this->rsyncdir = $this->Config->get('local.rsyncdir');

        $this->validate = true;
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

        // create required directories
        $this->App->out('Create dirs...');
        $this->create();

        // discover and set snapshot dirs
        $this->App->out('Discover dirs...');
        $arch1 = $arch2 = $this->map();

        #####################################
        # SORT DATA 
        #####################################
        foreach ($arch2 as $type => $snapshots)
        {
            //initiate base and end
            $base[$type] = '';
            $end[$type] = '';
            //set if archives
            if (count($arch2[$type]))
            {
                asort($arch2[$type]);
                $base[$type] = $arch2[$type][0];
                $end[$type] = (end($arch2[$type]));
            }
        }
        #####################################
        # COMPARE DATESTAMPS
        #####################################
        foreach ($arch2 as $type => $snapshots)
        {
            
            //no datestamp comparison needed
            if ($type == 'incremental')
            {
                $arch2[$type] [] = $this->newdir;
            }
            //datestamp comparison
            else
            {
                if ($end[$type])
                {
                    //validate
                    if (!preg_match("/[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}/", $end[$type], $m))
                    {
                        $this->App->fail("Wrong dirstamp format found, cannot continue!");
                    }
                    else
                    {
                        //directory timestamp
                        $ddatestamp = $m[0];

                        if($ddatestamp == '')
                        {
                            $this->App->fail('Rotation datestamp empty!');
                        }

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
                            if ($this->time_exceed($diff, $type))
                            {
                                $arch2[$type] [] = $this->newdir;
                            }
                        }
                    }
                }
                else
                {
                    $arch2[$type] [] = $this->newdir;
                }
            }
        }
        #####################################
        # SLICE
        #####################################
        //check how many dirs are desired 
        foreach ($arch2 as $type => $snapshots)
        {
            $n = $this->Config->get(['snapshots', $type]);
            $arch2[$type] = array_slice($arch2[$type], -$n, $n);
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
        foreach ($arch2 as $type => $snapshots)
        {
            if (count($snapshots))
            {
                foreach ($snapshots as $snapshot)
                {
                    $actions['remove'][$type] = array_diff($arch1[$type], $arch2[$type]);
                    $actions['add'][$type] = array_diff($arch2[$type], $arch1[$type]);
                }
            }
            else
            {
                $actions['remove'][$type] = $arch1[$type];
            }
        }
        //determine actions to take 
        foreach (array_keys($actions) as $action)
        {
            if (count($actions[$action]))
            {
                foreach ($actions[$action] as $type => $snapshots)
                {
                    if (count($snapshots))
                    {
                        foreach ($snapshots as $snapshot)
                        {
                            switch ($action)
                            {
                                case 'add':
                                    $message = "Add $snapshot to $type...";
                                    break;
                                case 'remove':
                                    $message = "Remove $snapshot from $type...";
                                    break;
                            }
                            $this->App->out($message, 'indent');
                            $this->$action($snapshot, $type);
                            //check if command returned ok
                            if($this->Cmd->is_error())
                            {
                                $this->App->fail('Cannot rotate snapshot "'.$snapshot.'". Command failed! Action: '.$action.' Type: '.$type);
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

        // list the the snapshots in the output
        $this->App->out('List snapshots...');
        $this->App->out();

        // output
        foreach($this->map() as $type => $snapshots)
        {
            $this->App->out($type);
            // sort reverse order
            krsort($snapshots);
            foreach($snapshots as $snapshot)
            {
                $this->App->out($snapshot, 'indent');
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
    function create()
    {
        #####################################
        # CHECK ARCHIVE DIR
        #####################################
        $this->App->out('Create hostdir subdirectories...');
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
        $this->App->out('Create archive subdirectories...');
        //validate dir
        foreach (array_keys($this->Config->get('snapshots')) as $d)
        {
            $dd = $this->archive_dir . '/' . $d;
            if (!is_dir($dd))
            {
                $this->App->out('Create subdirectory ' . $dd . '...');
                $this->Cmd->exe("mkdir -p " . $dd);
            }
        }
    }

    /**
     * Map the snapshot dir
     *
     * @return mixed
     */
    function map()
    {

        // construct
        $ArchiveMapper = ArchiveMapperFactory::create($this->App);
        $ArchiveMapper->init($this->validate);

        // do not validate more than once
        $this->validate = false;

        foreach($ArchiveMapper->get_messages() as $message)
        {
            $this->App->notice($message);
        }

        // get snapshots per type
        return $ArchiveMapper->map();
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
