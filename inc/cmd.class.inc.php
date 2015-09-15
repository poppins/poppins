<?php

class Cmd
{

    public $commands = [];
    
    private $error = false;
    
    public $output = '';

    function __construct()
    {
        $this->map = $this->map();
    }

    function exe($cmd)
    {
        //check if parsing is needed
        foreach (array_keys($this->map) as $c)
        {
            if (preg_match('/' . $c . '/', $c))
            {
               $cmd = $this->parse($cmd);
            }
        }

        //store command
        $this->cmd = $cmd;
        $this->commands []= $cmd;
        
        //redirect error to standard
        $o = trim(exec("$cmd", $output, $this->return));
        
        //output is an array, we want a string
        $this->output = implode("\n", $output);
               
        return $this->output;
    }

    public function is_error()
    {
         //if all is well, 0 is returned, else e.g. 127
        return (boolean)($this->return !== 0);
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
        $cmd['{CP}'] = 'cp';
        $cmd['{MV}'] = 'mv';
        $cmd['{RM}'] = 'rm';
        $cmd['{SSH}'] = 'ssh';
        $cmd['{GREP}'] = 'grep';
        $cmd['{DF}'] = 'df';
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
        $cmd['{GREP}'] = '/usr/bin/ggrep';
        $cmd['{DF}'] = '/usr/gnu/bin/df';
        return $cmd;
    }

}
