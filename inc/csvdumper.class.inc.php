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
 * Class CsvDumper contains functions that generate mysqldump commands
 */
class CsvDumper extends Dumper
{

    use CanDiscoverTables;

    protected $item_type = 'tables';

    function create_statements($tables)
    {
        $statements = [];
        // create statements
        foreach($tables as $table)
        {
            $statements [$table.' (csv)']= "'$this->mysql_executable -B -e \"SELECT * FROM $table\"' $this->gzip_pipe_cmd > $this->mysqldump_dir/$table.csv$this->gzip_extension_cmd";
        }
        return $statements;
    }
}
