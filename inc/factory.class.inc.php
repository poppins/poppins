<?php

class Factory
{

}

class BackupFactory extends Factory
{
    const base = 'Backup';
    
    function create($settings)
    {
        // build the class
        $classname = self::base;
        if (in_array($settings['filesystem']['type'], ['ZFS', 'BTRFS']))
        {
            $classname = $settings['filesystem']['type'].$classname;
        }
        else
        {
            $classname = ucfirst($settings['filesystem']['type']).$classname;
        }
        return new $classname($settings);
    }
}

class CmdFactory extends Factory
{
    const base = 'Commands';
    
    function create($App)
    {
        // build the class
        $classname = self::base;
        $classname = $App->OS.self::base;
        return new $classname();
    }
}

class RotatorFactory extends Factory
{
    const base = 'Rotator';
    
    function create($interval, $settings)
    {
        // build the class
        $classname = ucfirst($interval).self::base;
        if (in_array($settings['filesystem']['type'], ['ZFS', 'BTRFS']))
        {
            $classname = $settings['filesystem']['type'].$classname;
        }
        return new $classname($settings);
    }
}