<?php
class Validator
{
    /*
     * check if absolute path
     */
    static function is_absolute_path($path)
    {
        return (preg_match('/^\//', $path))? true:false;
    }

    /*
     * check if absolute path
     */
    static function is_integer($string)
    {
        return (preg_match("/^[0-9]+$/", $string))? true:false;
    }

    /*
     * check if relative home path
     */
    static function is_relative_home_path($path)
    {
        return ($path == '~' || preg_match('/^~\//', $path))? true:false;
    }

    /*
     * Return a diff with unexpected
     */
    static function diff_listing($dir, $allowed = [])
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

    static function contains_allowed_characters($string)
    {
        return (empty($string) || preg_match("#^[A-Za-z0-9/\\\\ \-\._\+\pL]+$#u", $string))? true:false;
    }
}
?>