<?php

trait CanDiscoverTables
{
    function discover_items()
    {
        //return the cache
        if($this->Session->is_set('cache.discovered-tables.'.$this->config_file))
        {
            return $this->Session->get('cache.discovered-tables.'.$this->config_file);
        }

        // discover all the databases
        $database_dumper = new DatabaseDumper($this->App, $this->config_file);
        $databases_discovered = $database_dumper->discover_items();

        $tables = [];

        foreach ($databases_discovered as $db)
        {
            $tables_tmp = $this->Cmd->exe("'$this->mysql_executable --skip-column-names -e \"use $db; show tables;\"'", true);

            $tables_tmp = explode("\n", $tables_tmp);

            // put in final array
            foreach($tables_tmp as $table)
            {
                //empty database is possible
                if (!empty($table))
                {
                    $tables []= $db.'.'.$table;
                }
            }
        }

        // cache the results
        $this->Session->set('cache.discovered-tables.'.$this->config_file, $tables);

        return $tables;
    }
}