<?php
/**
 * File DirectoryStructureFactory.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/Factory.php';

/**
 * Class DirectoryStructureFactory creates a DirectoryStructure class
 */
class DirectoryStructureFactory extends Factory
{
    const base = 'DirectoryStructure';

    /**
     * Create the object with the right classname
     *
     * @param $App Application class
     * @return mixed The object
     */
    public static function create($App)
    {
        //Config
        $Config = Config::get_instance();
        // build the class
        $classname = self::base;
        $classname = ucfirst($Config->get('local.snapshot-backend')).$classname;
        return new $classname($App);
    }
}
