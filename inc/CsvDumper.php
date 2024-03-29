<?php
/**
 * File CsvDumper.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__) . '/Dumper.php';
require_once dirname(__FILE__) . '/CanDiscoverTables.php';

/**
 * Class CsvDumper contains functions that generate mysqldump commands
 */
class CsvDumper extends Dumper
{

    use CanDiscoverTables;

    protected $item_type = 'tables';

    public function create_statements($tables)
    {
        $statements = [];
        // dump prefix
        $prefix = 'csv';
        // create statements
        foreach ($tables as $table) {
            $statements[$table . ' - csv'] = "'$this->mysql_executable -B -e \"SELECT * FROM $table\"' $this->gzip_pipe_cmd > $this->mysqldump_dir/$prefix.$table.txt$this->gzip_extension_cmd";
        }
        return $statements;
    }
}
