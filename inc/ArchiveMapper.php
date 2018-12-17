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

    protected $archive_dirs;

    protected $messages;

    protected $whitelist;

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

        //directories
        $this->archive_dir = $this->Config->get('local.hostdir') . '/archive';

        $this->messages = [];

        $this->dir_regex = '[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}';

        // get the types e.g. incremental, 10-minutely, etc
        $this->snapshot_types = array_keys($this->Config->get('snapshots'));

    }

    function init()
    {
        $archive_dirs = $this->get_archive_dirs();

        // get listing
        foreach($archive_dirs as $dir)
        {
            $this->validate($dir);
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
        $archive_dirs = [];

        // build an array of directories
        foreach ($this->snapshot_types as $sub_dir)
        {
            //full path
            $dir = $this->archive_dir . '/' . $sub_dir;

            if(is_dir($dir))
            {
                // get all snapshots from certain type e.g. incrementals
                $archive_dirs []= $dir;
            }

        }

        return $archive_dirs;
    }

    function get_messages()
    {
        return $this->messages;
    }

    function get_whitelist()
    {
        return $this->whitelist;
    }

    function get_filtered_filename($archive_dir)
    {
        //create whitelist for validation
        $filtered = [];

        // iterate through all snapshots
        foreach (scandir($archive_dir) as $found)
        {
            //check if dir
            $prefix = str_replace('.', '\.', $this->Config->get('local.hostdir-name'));
            if (is_dir("$archive_dir/$found"))
            {
                if (preg_match("/$prefix\.$this->dir_regex\.poppins$/", $found))
                {
                    // add to whitelist
                    $filtered [] = $found;
                }
            }
        }

        return $filtered;
    }

    function get_snapshots_per_category()
    {
        $snaphots = [];

        foreach($this->whitelist as $path => $files)
        {
            $pieces = explode('/', $path);
            $index = count($pieces) - 1;
            $snapshots[$pieces[$index]] = $files;
        }

        return $snapshots;
    }

    function validate($archive_dir)
    {
        $whitelist = $this->get_filtered_filename($archive_dir);
        $this->whitelist[$archive_dir] = $whitelist;

        $unclean_files = Validator::get_unclean_files($archive_dir, $whitelist);

        if (count($unclean_files))
        {
            foreach ($unclean_files as $file => $type)
            {
                $this->messages []= "Archive subdirectory $archive_dir not clean, unknown $type '$file'. Remove or rename to '_$file'..";
            }
        }

    }


}