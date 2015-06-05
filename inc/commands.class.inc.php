<?php

class Commands
{

    function __construct()
    {
        $this->map = $this->map();
    }
    
    function exe($cmd, $type = 'exec', $parse = false)
    {
        if($parse)
        {
            $cmd = $this->parse($cmd);
        }
        //return a sring or a boolean
        switch($type)
        {
            //return string
            case 'exec':
                return trim(shell_exec($cmd));
                break;
            //return false if error
            case 'passthru':
                passthru($cmd, $return);
                return !(boolean)$return;
                break;
        }
        
        return trim(exec($cmd));
    }
    
    function passthru($cmd)
    {
        $cmd = $this->parse($cmd);
        passthru($cmd, $return);
        return $return;
    }
    
    function parse($cmd)
    {
        $map = $this->map;
        $search = array_keys($map);
        $replace = array_values($map);
        $cmd = str_replace($search, $replace, $cmd);
        return $cmd;
    }
}

class LinuxCommands extends Commands
{

    function map()
    {
        $cmd = [];
        $cmd['{RM}'] = '/bin/rm';
        $cmd['{MV}'] = '/bin/mv';
        $cmd['{CP}'] = '/usr/bin/cp';
        return $cmd;
    }

}

class SunOSCommands extends Commands
{

    function map()
    {
        $cmd = [];
        $cmd['{RM}'] = '/usr/gnu/bin/rm';
        $cmd['{MV}'] = '/usr/bin/mv';
        $cmd['{CP}'] = '/bin/cp';
        return $cmd;
    }

}
