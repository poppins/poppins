<?php
/**
 * File tabledumper.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/dumper.class.inc.php';

/**
 * Class TableDumper contains functions that generate mysqldump commands
 */
class TableDumper extends Dumper
{

    protected $item_type = 'tables';

    function discover_items()
    {
        $databases = $this->Cmd->exe("'$this->mysql_exec --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'", true);

        $tables = [];

        foreach (explode("\n", $databases) as $db)
        {
            $tables_tmp = $this->Cmd->exe("'$this->mysql_exec --skip-column-names -e \"use $db; show tables;\"'", true);

            $tables = array_merge($tables, explode("\n", $tables_tmp));
        }

        return $tables;

    }

}
