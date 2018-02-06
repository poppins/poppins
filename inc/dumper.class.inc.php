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
    }

}
