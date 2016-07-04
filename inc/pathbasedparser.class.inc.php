<?php
/**
 * File pathbasedparser.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

//require parent class
require_once(dirname(__FILE__).'/parser.class.inc.php');

/**
 * Class PathBasedParser
 */
class PathBasedParser extends Parser
{
    /**
     * Get a value based on dot notation
     * E.g. $keys['foo.bar'] gets $array['foo']['bar']
     *
     * @param string $index The index (dotted string)
     * @param string $default Default value is empty unless otherwise
     * @return string Value stored in array
     */
    function get($index, $default = '')
    {
        //if no dotes, return index
        if(!preg_match('/\./', $index))
        {
            return $this->Store->stored()[$index];
        }

        $current = $this->Store->stored();
        $p = strtok($index, '.');

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
     * Check if value is set based on dotted notation
     * E.g. $keys['foo.bar'] gets $array['foo']['bar']
     *
     * @param string $index The index (dotted notation)
     * @return bool Is set or not?
     */
    function is_set($index)
    {
        //if no dotes, return index
        if(!preg_match('/\./', $index))
        {
            return isset($this->Store->stored()[$index]);
        }

        // dots
        $current = $this->Store->stored();
        $p = strtok($index, '.');

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
     * Set a value based on array notation
     * E.g. set('foo.bar', 'something') sets $array['foo']['bar'] to 'something'
     *
     * @param $index The keys of the array based on dotted string
     * @param $value The value to be set
     * @return boolean Return true if successful
     */
    function set($index, $value)
    {
        $index = explode('.', $index);

        $parser = new KeyBasedParser($this->Store);
        return $parser->set($index, $value);
    }
}