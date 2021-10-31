<?php
/**
 * File lib.inc.php
 *
 * Contains some general functions and includes all required classes
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */
#####################################
# FUNCTIONS
#####################################
/**
 * Dump variable and die()
 *
 * @param string $s
 */
function dd($s = '')
{
    var_dump($s);
    die();
}
/**
 * Dump variable
 *
 * @param string $s
 */
function d($s = '')
{
    var_dump($s);
}
#####################################
# INCLUDE CLASSES
#####################################
//scan all files and require them
$files = scandir(dirname(__FILE__));
foreach ($files as $file) {
    if (preg_match('/.php$/', $file)) {
        require_once $file;
    }

}
