<?php

class Factory
{
    private $App;
    
    private $settings;
}

class BackupFactory extends Factory
{
    const base = 'Backup';
    
    function create($App)
    {
        //settings
        $settings = $App->settings;
        // build the class
        $classname = self::base;
        if (in_array($settings['local']['filesystem'], ['ZFS', 'BTRFS']))
        {
            $classname = $settings['local']['filesystem'].$classname;
        }
        else
        {
            $classname = ucfirst($settings['local']['filesystem']).$classname;
        }
        return new $classname($App);
    }
}

class CmdFactory extends Factory
{
    const base = 'Cmd';
    
    function create($OS = 'Linux')
    {
        // build the class
        $classname = self::base;
        $classname = $OS.self::base;
        return new $classname();
    }
}

class RotatorFactory extends Factory
{
    const base = 'Rotator';
    
    function create($App, $interval)
    {
        //settings
        $settings = $App->settings;
        // build the class
        $classname = ucfirst($interval).self::base;
        if (in_array($settings['local']['filesystem'], ['ZFS', 'BTRFS']))
        {
            $classname = $settings['local']['filesystem'].$classname;
        }
        return new $classname($App);
    }
}