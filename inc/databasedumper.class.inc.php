<?php
/**
 * File databasedumper.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/dumper.class.inc.php';

/**
 * Class DatabaseDumper contains functions that generate mysqldump commands
 */
class DatabaseDumper extends Dumper
{

    protected $item_type = 'databases';

    /*
     * Get all the databases
     */
    function discover_items()
    {
        $databases = $this->Cmd->exe("'$this->mysql_exec --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'", true);

        return explode("\n", $databases);
    }

}
