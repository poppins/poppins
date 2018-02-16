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

        $databases = $this->Cmd->exe("'$this->mysql_executable --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'", true);

        $tables = [];

        foreach (explode("\n", $databases) as $db)
        {
            $tables_tmp = $this->Cmd->exe("'$this->mysql_executable --skip-column-names -e \"use $db; show tables;\"'", true);

            $tables_tmp = explode("\n", $tables_tmp);

            // put in final array
            foreach($tables_tmp as $table)
            {
                $tables []= $db.'.'.$table;
            }
        }

        // cache the results
        $this->Session->set('cache.discovered-tables.'.$this->config_file, $tables);

        return $tables;
    }
}