<?php
/**
 * File config.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

//require parent class
require_once dirname(__FILE__) . '/Store.php';

/**
 * Class Config contains all ini file directives
 */
class Config extends Store
{
    /**
     * Returns instance (singleton)
     *
     * @return null|static
     */
    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new static();
        }

        return $instance;
    }
}
