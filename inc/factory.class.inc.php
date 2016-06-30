<?php
/**
 * File config.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Factory creates classes (factory pattern)
 */
class Factory {}

/**
 * Class BackupFactory creates a Backup class
 */
class BackupFactory extends Factory
{
    const base = 'Backup';

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

/**
 * Class CmdFactory creates a Cmd class
 */
class RotatorFactory extends Factory
{
    const base = 'Rotator';

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