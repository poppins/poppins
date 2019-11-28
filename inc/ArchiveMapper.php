<?php
/**
 * File ArchiveMapper.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class ArchiveMapper.
 */
class ArchiveMapper
{
    protected $App;

    protected $Cmd;

    protected $messages;

    protected $snapshots;

    protected $validate;

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

        // messages
        $this->messages = [];

        // snapshots per directory
        $this->snapshots = [];

    }

    /**
     * @param bool $validate Validate the snapshot dir
     */
    function init($validate = true)
    {
        // validate the arch dir
        $this->validate = $validate;

        // get listing
        foreach($this->get_archive_dirs() as $dir)
        {
            // add dir to snapshots
            $this->snapshots[$dir] = [];

            //create whitelist for validation
            $unclean_files = [];

            // iterate through all snapshots
            foreach (scandir($dir) as $file_found)
            {
                $regex = $this->snapshot_regex();

                // check end
                if (preg_match("/$regex/", $file_found))
                {
                    // add to whitelist
                    $this->snapshots[$dir] []= $file_found;
                }
                // ignore dot
                elseif(!in_array($file_found, ['.', '..']) && !preg_match('/^_/', $file_found))
                {
                    $unclean_files [$file_found] = filetype($dir.'/'.$file_found);
                }
            }

            // warn if unclean file
            if($this->validate)
            {
                if (count($unclean_files))
                {
                    foreach ($unclean_files as $file => $type)
                    {
                        $this->messages [] = "Archive (snapshot) subdirectory $dir not clean, unknown $type '$file'. Remove or rename to '_$file'..";
                    }
                }
            }
        }
    }

    /**
     * Function will scan for each subdirectory in /archive
     *
     * @param $validate Validate if unknown files or directories
     * @return array The directories
     */
    function get_archive_dirs()
    {
        // dirs
        $dirs = [];

        //  get the types e.g. incremental, 10-minutely, etc
        foreach (array_keys($this->Config->get('snapshots')) as $sub_dir)
        {
            // base archive dir
            $base_dir = $this->Config->get('local.hostdir') . '/archive';

            // full path
            $dir = $base_dir . '/' . $sub_dir;

            if(is_dir($dir))
            {
                // get all snapshots from certain type e.g. incrementals
                $dirs []= $dir;
            }

        }

        return $dirs;
    }

    function get_messages()
    {
        return $this->messages;
    }

    /**
     * Get all snapshots per type
     *
     * @return array
     */
    function map()
    {
        $map = [];

        foreach($this->snapshots as $path => $files)
        {
            $pieces = explode('/', $path);
            $index = count($pieces) - 1;
            $map[$pieces[$index]] = $files;
        }

        return $map;
    }


    /**
     * The regular expression of the snapshot
     *
     * @return string
     */
    function snapshot_regex()
    {
        //check if dir
        $hostname = str_replace('.', '\.', $this->Config->get('local.hostdir-name'));

        $regex = "^$hostname\.[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.poppins$";

        return $regex;
    }

}
