<?php

class Cmd
{

    private $error = false;

    function __construct()
    {
        $this->map = $this->map();
    }

    function exe($cmd, $type = 'exec', $parse = false)
    {
        if ($parse)
        {
            $cmd = $this->parse($cmd);
        }
        //return a sring or a boolean
        switch ($type)
        {
            //return string
            case 'exec':
                $res = trim(shell_exec("$cmd"));
                // only works if "&& echo OK" is added to command
                //TODO do this differently
                $this->error = (substr($res, -2, 2) == 'OK') ? false : true;
                return $res;
                break;
            //return false if error
            case 'passthru':
                passthru($cmd, $return);
                $this->error = (boolean) $return;
                return !$this->error;
                break;
        }

        return trim(exec($cmd));
    }

    function is_error()
    {
        return $this->error;
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

class LinuxCmd extends Cmd
{

    function map()
    {
        $cmd = [];
        $cmd['{CP}'] = '/usr/bin/cp';
        $cmd['{MV}'] = '/bin/mv';
        $cmd['{RM}'] = '/bin/rm';
        $cmd['{SSH}'] = '/usr/bin/ssh';
        return $cmd;
    }

}

class SunOSCmd extends Cmd
{

    function map()
    {
        $cmd = [];
        $cmd['{CP}'] = '/bin/cp';
        $cmd['{MV}'] = '/usr/bin/mv';
        $cmd['{RM}'] = '/usr/gnu/bin/rm';
        $cmd['{SSH}'] = '/opt/csw/bin/ssh';
        return $cmd;
    }

}
