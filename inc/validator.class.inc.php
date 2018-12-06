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
     * @param boolean $exact_match Search must match string exactly
     * @return array Files or directories that differ
     */
    static function get_unclean_files($dir, $allowed = [], $exact_match = true)
    {
        $unclean_files = [];
        $scan = scandir($dir);
        # var_dump($allowed);
        foreach($scan as $found)
        {
            # echo $found."\n";
            // ignore dot
            if(in_array($found, ['.', '..']))
            {
                continue;
            }
            // ignore underscore
            if(preg_match('/^_/', $found))
            {
                continue;
            }
            //match based on regex
            if(!$exact_match)
            {
                $match = false;
                foreach($allowed as $a)
                {
                    if (preg_match('/^'.$a.'/', $found))
                    {
                        $match = true;
                        break;
                    }
                }
                // no match, fail
                if(!$match)
                {
                    $unclean_files [$found] = filetype($dir.'/'.$found);
                }
            }
            elseif(!in_array($found, $allowed))
            {
                $unclean_files [$found] = filetype($dir.'/'.$found);
            }
        }
        return $unclean_files;
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

    /**
     * Check if string contains trailing slash
     *
     * @param $path The string to be validated
     * @return bool Valid or not?
     */
    static function contains_trailing_slash($path)
    {
        return (preg_match('/.+\/$/', $path))? true:false;
    }
}
