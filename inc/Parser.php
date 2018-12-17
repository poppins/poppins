<?php
/**
 * File Parser.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Parser 
 */
abstract class Parser
{
    // Store class
    protected $Store;

    /**
     * Parser constructor.
     * @param Store $Store The Store class
     */
    function __construct(Store $Store)
    {
        $this->Store = $Store;
    }

    /**
     * Getter function
     *
     * @param mixed $index Dotted string or array of keys
     * @param string $default Default value if nothing found
     * @return string Returns the value
     */
    abstract function get($index, $default = '');

    /**
     * Function to check if a value is is set
     *
     * @param $index Dotted string or array of keys
     * @return boolean If is set or not
     */
    abstract function is_set($index);

    /**
     * Setter function
     *
     * @param $index Dotted string or array of keys
     * @param $value Default value if no value
     * @return boolean Return true if successful
     */
    abstract function set($index, $value);

}