<?php
/**
 * File SunOSCmd.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__) . '/Cmd.php';

/**
 * Class SunOSCmd is used for all SunOS commands
 */
class SunOSCmd extends Cmd
{

    public function map()
    {
        $cmd = [];
        $cmd['{CP}'] = '/bin/cp';
        $cmd['{MV}'] = '/usr/bin/mv';
        $cmd['{RM}'] = '/usr/gnu/bin/rm';
        $cmd['{SSH}'] = '/opt/csw/bin/ssh';
        $cmd['{GREP}'] = '/usr/bin/ggrep';
        $cmd['{DF}'] = '/usr/gnu/bin/df';
        return $cmd;
    }

}
