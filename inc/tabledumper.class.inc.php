<?php
/**
 * File tabledumper.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/dumper.class.inc.php';
require_once dirname(__FILE__).'/candiscovertable.trait.inc.php';

/**
 * Class TableDumper contains functions that generate mysqldump commands
 */
class TableDumper extends Dumper
{
    use CanDiscoverTables;

    protected $item_type = 'tables';

    function create_statements($tables)
    {
        $statements = [];
        // create statements
        foreach($tables as $table)
        {
            $statements [$table.' (sql)']= "'$this->mysqldump_executable $this->mysqldump_options $table' $this->gzip_pipe_cmd > $this->mysqldump_dir/$table.sql$this->gzip_extension_cmd";
        }
        return $statements;
    }

}
