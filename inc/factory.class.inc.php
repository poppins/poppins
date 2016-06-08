<?php

class Factory {}

class BackupFactory extends Factory
{
    const base = 'Backup';
    
    public static function create($App)
    {
        //Config
        $Config = Config::get_instance();
        // build the class
        $classname = self::base;
        if (in_array($Config->get('local.filesystem'), ['ZFS', 'BTRFS']))
        {
            $classname = $Config->get('local.filesystem').$classname;
        }
        else
        {
            $classname = ucfirst($Config->get('local.filesystem')).$classname;
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
        //Config
        $Config = Config::get_instance();
        // build the class
        $classname = self::base;
        $classname = ucfirst($Config->get('local.filesystem')).$classname;
        return new $classname($App);
    }
}