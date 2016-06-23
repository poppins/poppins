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
    public function store($array = [])
    {
        $this->stored = array_replace_recursive($this->stored, $array);
    }

    /**
     * Generic function which gets a value based on an index, dotted string or array
     *
     * @param bool $index The index (dotted string or array)
     * @param string $default Default value is empty unless otherwise
     * @return string Value stored in array
     */
    public function get($index = false, $default = '')
    {
        //get all values
        if(!$index)
        {
            ksort($this->stored);
            return $this->stored;
        }
        //array
        elseif(is_array($index))
        {
            return $this->get_key_based($index, $default);
        }
        //string
        else
        {
            return $this->get_path_based($index, $default);
        }
    }

    /**
     * Get a value based on array notation
     * E.g. $keys['foo', 'bar'] gets $array['foo']['bar']
     *
     * @param array $keys The index (array)
     * @param string $default Default value is empty unless otherwise
     * @return string Value stored in array
     */
    public function get_key_based($keys, $default = '')
    {
        $i = 1;
        $c = count($keys);

        $tmp = $this->stored;

        foreach ($keys as $k)
        {
            if($i == $c)
            {
                if(isset($tmp[$k]))
                {
                    return $tmp[$k];
                }
                else
                {
                    return $default;
                }
            }
            else
            {
                $tmp = $tmp[$k];
            }
            $i++;
        }
    }

    /**
     * Get a value based on dot notation
     * E.g. $keys['foo.bar'] gets $array['foo']['bar']
     *
     * @param string $path The index (dotted string)
     * @param string $default Default value is empty unless otherwise
     * @return string Value stored in array
     */
    public function get_path_based($path, $default = '')
    {
        //if no dotes, return index
        if(!preg_match('/\./', $path))
        {
            return $this->stored[$path];
        }

        $current = $this->stored;
        $p = strtok($path, '.');

        while ($p !== false)
        {
            if (!isset($current[$p]))
            {
                return $default;
            }
            $current = $current[$p];
            $p = strtok('.');
        }
        return $current;
    }

    /**
     * Generic function which checks if value is set in array
     *
     * @param mixed $index The index (array or dotted string)
     * @return bool Is set or not
     */
    public function is_set($index)
    {
        //array
        if(is_array($index))
        {
            return $this->is_set_key_based($index);
        }
        //string
        else
        {
            return $this->is_set_path_based($index);
        }
    }

    //TODO remove code dupication (get_key_based)
    /**
     * Check if value is set based on array notation
     * E.g. $keys['foo', 'bar'] gets $array['foo']['bar']
     *
     * @param array $keys The index (array)
     * @return bool Is set or not?
     */
    public function is_set_key_based($keys)
    {
        $i = 1;
        $c = count($keys);

        $tmp = $this->stored;

        foreach ($keys as $k)
        {
            if($i == $c)
            {
                return (isset($tmp[$k]));
            }
            else
            {
                $tmp = $tmp[$k];
            }
            $i++;
        }
    }

    //TODO remove code dupication (get_path_based)
    /**
     * Check if value is set based on dotted notation
     * E.g. $keys['foo.bar'] gets $array['foo']['bar']
     *
     * @param string $path The index (dotted notation)
     * @return bool Is set or not?
     */
    public function is_set_path_based($path)
    {
        //if no dotes, return index
        if(!preg_match('/\./', $path))
        {
            return isset($this->stored[$path]);
        }

        // dots
        $current = $this->stored;
        $p = strtok($path, '.');

        while ($p !== false)
        {
            if (!isset($current[$p]))
            {
                return false;
            }
            $current = $current[$p];
            $p = strtok('.');
        }
        return isset($current);
    }

    /**
     * Generic function which sets a value based on an index,
     * dotted string or array
     *
     * @param $index Set the key (array or dotted string)
     * @param $value Set the value
     */
    public function set($index, $value)
    {
        if(is_array($index))
        {
            $this->set_key_based($index, $value);
        }
        else
        {
            $this->set_path_based($index, $value);
        }
    }

    /**
     * Set a value based on array notation
     * E.g. set('foo.bar', 'something') sets $array['foo']['bar'] to 'something'
     *
     * @param $path The keys of the array based on dotted string
     * @param $value The value to be set
     */
    private function set_path_based($path, $value)
    {
        $keys = explode('.', $path);
        return $this->set_key_based($keys, $value);
    }

    /**
     * Set a value based on array notation
     * E.g. set(['foo', 'bar'], 'something') sets $array['foo']['bar'] to 'something'
     *
     * @param $keys The keys of the array
     * @param $value The value to be set
     */
    private function set_key_based($keys, $value)
    {
        $res = array();
        $tmp =& $res;

        foreach ($keys as $k)
        {
            $tmp[$k] = array();
            $tmp =& $tmp[$k];
        }

        $tmp = $value;

        $this->store($res);
    }

}
