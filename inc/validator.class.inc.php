<?php
class Validator
{
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
}
?>