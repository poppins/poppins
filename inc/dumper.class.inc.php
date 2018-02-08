<?php
/**
 * File dumper.class.inc.php
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

    protected $mysqldump_exec;

    protected $mysql_exec;

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

        // executables
        $this->mysqldump_exec = "mysqldump --defaults-file=$config_file";
        $this->mysql_exec = "mysql --defaults-file=$config_file";
    }

    function get()
    {
        $this->App->out("Backup $this->item_type from $this->config_file");

        // retrieve all items
        $items_discovered = $this->discover_items();

        if(!count($items_discovered))
        {
            $this->App->fail('No '.$this->item_type.' found!');
        }

        // check if there is a need for slicing
        $items_config = [];
        foreach($this->slice_types as $slice_type)
        {
            if($this->Config->is_set("mysql.$slice_type-$this->item_type"))
            {
                $pattern = $this->Config->get('mysql.' . $slice_type . '-'.$this->item_type);
                // check if directive is not an empty string
                if(!empty($pattern))
                {
                    $items_config[$slice_type] = explode(',', $pattern);
                    // set slice flag
                    $this->slice = true;
                }
            }
        }

        // slice the items if needed
        if($this->slice)
        {
            //check if these items exist
            $exists_check = true;
            $items_matched = [];
            foreach ($items_config as $slice_type => $pattern)
            {
                // match items on regex
                if(preg_match('/^\/.+\/$/', $pattern))
                {
                    $regex_matched = preg_grep($pattern, $items_discovered);
                    d($regex_matched);
                    // not found
                    if (!count($regex_matched))
                    {
                        $message = ucfirst($slice_type) . ' '.$this->item_type.' regex pattern "' . $pattern . '" not found!';
                        // warn first, fail later
                        $this->App->warn($message);
                        $exists_check = false;
                    }
                    else
                    {
                        // add matched items
                        foreach($regex_matched as $m)
                        {
                            array_push($items_matched[$slice_type], $m);
                        }
                    }
                }
                // match items on string
                else
                {
                    // not found
                    if(!in_array($pattern, $items_discovered))
                    {
                        $message = ucfirst($slice_type) . ' '.$this->item_type.' matched pattern "' . $pattern . '" not found!';
                        // warn first, fail later
                        $this->App->warn($message);
                        $exists_check = false;
                    }
                    else
                    {
                        $items_matched[$slice_type] []= $pattern;
                    }
                }
            }
            // one or more databases does not exist
            if (!$exists_check)
            {
                $this->App->fail('Include/exclude '.$this->item_type.' not found!');
            }

            $items_backup = $items_matched['included'];
        }
        else
        {
            $items_backup = $items_discovered;
        }

        // exclude items
        if (count($items_matched['excluded']))
        {
            // remove the excluded items from the array
            foreach ($items_matched['excluded'] as $exclude)
            {
                if (($key = array_search($exclude, $items_backup)) !== false)
                {
                    unset($items_backup[$key]);
                }
            }
        }

        dd($items_backup);

    }

}
