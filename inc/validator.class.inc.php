<?php
/**
 * File validator.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Validator contains functions which validate strings, directories, etc
 */
class Validator
{
    /**
     * Check if string is an absolute path
     *
     * @param $path The string to be validated
     * @return bool Valid or not?
     */
    static function is_absolute_path($path)
    {
        return (preg_match('/^\//', $path))? true:false;
    }

    /**
     * Check if string is an integer value
     *
     * @param $string The string to be validated
     * @return bool Valid or not?
     */
    static function is_integer($string)
    {
        return (preg_match("/^[0-9]+$/", $string))? true:false;
    }

    /**
     * Check if string is a relative home path
     *
     * @param $path The string to be validated
     * @return bool Valid or not?
     */
    static function is_relative_home_path($path)
    {
        return ($path == '~' || preg_match('/^~\//', $path))? true:false;
    }

    /**
     * Check if actual dir listing is as expected and
     * if not, return the difference
     *
     * @param $dir Directory
     * @param array $allowed Allowed files and directories
     * @return array Files or directories that differ
     */
    static function contains_allowed_files($dir, $allowed = [])
    {
        $unexpected = [];
        $scan = scandir($dir);
        foreach($scan as $found)
        {
            // ignore dot
            if(in_array($found, ['.', '..']))
            {
                continue;
            }
            if(!in_array($found, $allowed))
            {
                $unexpected [$found] = filetype($dir.'/'.$found);
            }
        }
        return $unexpected;
    }

    /**
     * Check if string contains allowed characters
     *
     * @param $string The string to be validated
     * @return bool Valid or not?
     */
    static function contains_allowed_characters($string)
    {
        return (empty($string) || preg_match("#^[A-Za-z0-9/\\\\ \-\._\+\pL]+$#u", $string))? true:false;
    }
}
