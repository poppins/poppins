<?php
/**
 * File DatabaseDumper.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/Dumper.php';

/**
 * Class DatabaseDumper contains functions that generate mysqldump commands
 */
class DatabaseDumper extends Dumper
{

    protected $item_type = 'databases';

    /**
     * Create the statements
     * @param $databases
     */
    function create_statements($databases)
    {
        $statements = [];
        //ignore tables
        $tables_ignore = [];
        // add tables to ignore -
        if($this->Config->is_set('mysql.ignore-tables') && !empty($this->Config->get('mysql.ignore-tables')))
        {
            //check if these items exist
            $exists_check = true;
            // get patterns
            $patterns = explode(',', $this->Config->get('mysql.ignore-tables'));
            foreach ($patterns as $pattern)
            {
                // discover all the tables
                $table_dumper = new TableDumper($this->App, $this->config_file);
                $tables_discovered = $table_dumper->discover_items();
                // match according to pattern
                $matched = $table_dumper->get_items_matched($tables_discovered, $pattern);
                if(count($matched))
                {
                    // add all items to the array
                    foreach ($matched as $m)
                    {
                        array_push($tables_ignore, $m);
                    }
                }
                else
                {
                    $exists_check = false;
                }
            }
            // one or more tables does not exist
            if (!$exists_check)
            {
                $this->App->fail('Ignore tables pattern "' . $pattern . '" not found!');
            }
        }

        // ignore tables
        $tables_ignore_cmd = [];
        if(count($tables_ignore))
        {
            foreach ($tables_ignore as $table)
            {
                $tables_ignore_cmd [] = '--ignore-table='.$table;
            }
        }
        $tables_ignore_cmd = implode(' ', $tables_ignore_cmd);
        // create statement
        if ($this->Config->get('mysql.create-database'))
        {
            $this->mysqldump_options .= ' --databases';
        }
        // dump prefix
        $prefix = 'db';
        // loop through databases
        foreach($databases as $db)
        {
            $statements [$db] = "'$this->mysqldump_executable $tables_ignore_cmd $this->mysqldump_options $db' $this->gzip_pipe_cmd > $this->mysqldump_dir/$prefix.$db.sql$this->gzip_extension_cmd";
        }
        // return the statements
        return $statements;
    }

    /*
     * Get all the databases
     */
    function discover_items()
    {
        //return the cache
        if($this->Session->is_set('cache.discovered-databases.'.$this->config_file))
        {
            return $this->Session->get('cache.discovered-databases.'.$this->config_file);
        }

        $databases = $this->Cmd->exe("'$this->mysql_executable --skip-column-names -e \"show databases\" | grep -v \"^sys$\" | grep -v \"^information_schema$\"'", true);

        // create array
        $databases = explode("\n", $databases);

        // cache the results
        $this->Session->set('cache.discovered-databases.'.$this->config_file, $databases);

        return $databases;
    }

}
