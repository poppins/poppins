<?php
/**
 * File CmdFactory.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */
 
require_once dirname(__FILE__).'/Factory.php';

/**
 * Class CmdFactory creates a Cmd class
 */
class CmdFactory extends Factory
{
    const base = 'Cmd';

    /**
     * Create the object with the right classname
     *
     * @param $App Application class
     * @return mixed The object
     */
    public static function create($OS = 'Linux')
    {
        // build the class
        $classname = self::base;
        $classname = $OS.self::base;
        return new $classname();
    }
}
