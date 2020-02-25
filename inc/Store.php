<?php
/**
 * File store.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Store stores data in an array. Values may be stored and retrieved using
 * array keys or dotted strings (singleton pattern)
 */
class Store
{

    protected $stored;

    /**
     * Store constructor.
     */
    function __construct()
    {
        $this->stored = [];
    }

    /**
     * Returns instance (singleton)
     *
     * @return null|static
     */
    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance)
        {
            $instance = new static();
        }

        return $instance;
    }

    /**
     * Store an array of key/value pairs. Will replace if already exists.
     *
     * @param array $array The array to be stored
     */
    public function update($array = [])
    {
        $this->stored = array_replace_recursive($this->stored, $array);
    }

    /**
     * @param $method
     * @param $index
     * @param null $value
     * @return mixed
     */
    protected function select_parser($method, $index, $value = null)
    {
        //array
        if(is_array($index))
        {
            $class = new KeyBasedParser($this);
        }
        //string
        else
        {
            $class = new PathBasedParser($this);
        }

        return ($method == 'is_set')
            ? $class->{$method}($index)
            : $class->{$method}($index, $value);
    }

    /**
     * Generic function which gets a value based on an index, dotted string or array
     *
     * @param bool $index The index (dotted string or array)
     * @param string $value Default value is empty unless otherwise
     * @return string Value stored in array
     */
    public function get($index = false, $value = '')
    {
        //get all values
        if(!$index)
        {
            ksort($this->stored);
            return $this->stored;
        }
        // select parser
        else
        {
            return $this->select_parser('get', $index, $value);
        }
    }

    /**
     * Generic function which checks if value is set in array
     *
     * @param mixed $index The index (array or dotted string)
     * @return bool Is set or not
     */
    public function is_set($index)
    {
        // select parser
        return $this->select_parser('is_set', $index);
    }

    /**
     * Generic function which sets a value based on an index,
     * dotted string or array
     *
     * @param $index Set the key (array or dotted string)
     * @param $value Set the value
     * @return bool Success
     */
    public function set($index, $value)
    {
        // select parser
        return $this->select_parser('set', $index, $value);
    }

    /**
     * @return array Returns the array
     */
    public function stored()
    {
        return $this->stored;
    }

//    /**
//     * Generic function which unsets a value based on an index,
//     * dotted string or array
//     *
//     * @param $index Unset the key (array or dotted string)
//     * @return bool Success
//     */
//    // TODO
//    public function unset($index)
//    {
//
//    }

}


