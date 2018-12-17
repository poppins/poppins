<?php
/**
 * File Dumper.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Dumper contains functions that generate mysqldump commands
 */
abstract class Dumper
{
    // items to backup: tables or databases
    protected $items;

    protected $gzip_pipe_cmd;
    protected $gzip_extension_cmd;

    protected $mysqldump_compress;
    protected $mysqldump_dir;
    protected $mysqldump_executable;
    protected $mysqldump_options;

    protected $mysql_executable;

    // if the arrays must be sliced or not
    protected $slice = false;

    function __construct($App, $config_file)
    {
        //mysql config file
        $this->config_file = $config_file;

        // Application class
        $this->App = $App;

        // Cmd class
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

        // executables & options
        $this->mysql_executable = "mysql --defaults-file=$config_file";
        $this->mysqldump_executable = "mysqldump --defaults-file=$config_file";
        $this->mysqldump_options = '--routines --single-transaction --quick';

        // compress the dumps
        $this->mysqldump_compress = ($this->Config->is_set('mysql.compress'))? $this->Config->get('mysql.compress'):true;

        // create instance dir
        $instance = preg_replace('/^.+my\.cnf(\.)?/', '', $config_file);
        $instance = (empty($instance)) ? 'default':$instance ;
        $this->mysqldump_dir = $this->Session->get('mysql.dumpdir.'.$instance);

        // gzip
        $this->gzip_pipe_cmd = ($this->mysqldump_compress)? '| gzip':'';
        $this->gzip_extension_cmd = ($this->mysqldump_compress)? '.gz':'';

        // slice types
        $this->slice_types = ['included','excluded'];
    }

    abstract function create_statements($items);

    function get_items_to_backup()
    {
        // retrieve all items
        $items_discovered = $this->discover_items();

        if (!count($items_discovered))
        {
            $this->App->fail('No ' . $this->item_type . ' found!');
        }

        // check if there is a need for slicing
        $items_config = [];
        foreach ($this->slice_types as $slice_type)
        {
            if ($this->Config->is_set("mysql.$slice_type-$this->item_type"))
            {
                $pattern = $this->Config->get('mysql.' . $slice_type . '-' . $this->item_type);
                // check if directive is not an empty string
                if (!empty($pattern))
                {
                    $items_config[$slice_type] = explode(',', $pattern);
                    // set slice flag
                    $this->slice = true;
                }
            }
        }
        // slice the items if needed
        if ($this->slice)
        {
            $items_matched = [];
            // excluded, included
            foreach($this->slice_types as $slice_type)
            {
                // check if slice type is set
                if (!isset($items_config[$slice_type]))
                {
                    continue;
                }
                //check if these items exist
                $exists_check = true;
                foreach ($items_config[$slice_type] as $pattern)
                {
                    // match items
                    $matched = $this->get_items_matched($items_discovered, $pattern);
                    // no matches found
                    if(count($matched))
                    {
                        // check if the array exists, create if needed
                        if (!isset($items_matched[$slice_type]))
                        {
                            $items_matched[$slice_type] = [];
                        }
                        // add all items to the array
                        foreach ($matched as $m)
                        {
                            array_push($items_matched[$slice_type], $m);
                        }
                    }
                    else
                    {
                        // if empty array found, fail later
                        $exists_check = false;
                    }
                }
                // one or more databases does not exist
                if (!$exists_check)
                {
                    $this->App->fail('Included or excluded ' . $this->item_type . ' not found!');
                }
            }
        }

        // get the items to backup
        if(isset($items_matched['included']))
        {
            $items_to_backup = $items_matched['included'];
        }
        else
        {
            $items_to_backup = $items_discovered;
        }

        // exclude items
        if (isset($items_matched['excluded']) && count($items_matched['excluded']))
        {
            // remove the excluded items from the array
            foreach ($items_matched['excluded'] as $exclude)
            {
                if (($key = array_search($exclude, $items_to_backup)) !== false)
                {
                    unset($items_to_backup[$key]);
                }
            }
        }
        // return all the items
        return $items_to_backup;
    }

    function get_commands()
    {
        $items_to_backup = $this->get_items_to_backup();

        return $this->create_statements($items_to_backup);
    }

    /**
     * @param $items
     * @param $pattern
     */
    function get_items_matched($items, $pattern)
    {
        // initiate
        $matched = [];
        // match items on regex
        if (preg_match('/^\/.*\/$/', $pattern))
        {
            $matched = preg_grep($pattern, $items);
            // not found
            if (!count($matched))
            {
                $message = ucfirst($this->item_type). ' regex pattern "' . $pattern . '" not found!';
                // warn first, fail later
                $this->App->warn($message);
            }
        }
        // match items on string
        else
        {
            // not found
            if (!in_array($pattern, $items))
            {
                $message = ucfirst($this->item_type) . ' string "' . $pattern . '" not found!';
                // warn first, fail later
                $this->App->warn($message);
            }
            else
            {
                $matched = [$pattern];
            }
        }
        return $matched;
    }
}
