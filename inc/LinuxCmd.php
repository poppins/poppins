<?php
/**
 * File LinuxCmd.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__) . '/Cmd.php';

/**
 * Class LinuxCmd is used for all linux commands
 */
class LinuxCmd extends Cmd
{

    public function map()
    {
        $cmd = [];
        $cmd['{CP}'] = 'cp';
        $cmd['{MV}'] = 'mv';
        $cmd['{RM}'] = 'rm';
        $cmd['{SSH}'] = 'ssh';
        $cmd['{GREP}'] = 'grep';
        $cmd['{DF}'] = 'df';
        return $cmd;
    }

}
