<?php
/**
 * File KeyBasedParser.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

//require parent class
require_once dirname(__FILE__) . '/Parser.php';

/**
 * Class PathBasedParser
 */
class KeyBasedParser extends Parser
{
    /**
     * Get a value based on array notation
     * E.g. $keys['foo', 'bar'] gets $array['foo']['bar']
     *
     * @param array $index The index (array)
     * @param string $default Default value is empty unless otherwise
     * @return string Value stored in array
     */
    public function get($index, $default = '')
    {
        $i = 1;
        $c = count($index);

        $tmp = $this->Store->stored();

        foreach ($index as $k) {
            if ($i == $c) {
                if (isset($tmp[$k])) {
                    return $tmp[$k];
                } else {
                    return $default;
                }
            } else {
                $tmp = $tmp[$k];
            }
            $i++;
        }
    }

    /**
     * Check if value is set based on array notation
     * E.g. $keys['foo', 'bar'] gets $array['foo']['bar']
     *
     * @param array $index The index (array)
     * @return bool Is set or not?
     */
    public function is_set($index)
    {
        $i = 1;
        $c = count($index);

        $tmp = $this->Store->stored();

        foreach ($index as $k) {
            if ($i == $c) {
                return (isset($tmp[$k]));
            } else {
                $tmp = $tmp[$k];
            }
            $i++;
        }
    }

    /**
     * Set a value based on array notation
     * E.g. set(['foo', 'bar'], 'something') sets $array['foo']['bar'] to 'something'
     *
     * @param $index The keys of the array
     * @param $value The value to be set
     * @return boolean Return true if successful
     */
    public function set($index, $value)
    {
        $res = array();
        $tmp = &$res;

        foreach ($index as $k) {
            $tmp[$k] = array();
            $tmp = &$tmp[$k];
        }

        $tmp = $value;

        $this->Store->update($res);
    }
}
