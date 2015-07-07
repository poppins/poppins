<?php

class Factory
{
    private $App;
    
    private $settings;
}

class BackupFactory extends Factory
{
    const base = 'Backup';
    
    public static function create($App)
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
    
    public static function create($OS = 'Linux')
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
    
    public static function create($App)
    {
        //settings
        $settings = $App->settings;
        // build the class
        $classname = self::base;
        $classname = ucfirst($settings['local']['filesystem']).$classname;
        return new $classname($App);
    }
}