<?php

class Backup
{
    public $App;
    public $Cmd;
      
    public $settings;
    
            
    function __construct($settings)
    {
        $this->settings = $settings;
    }
    
    function init()
    {
        //prepare 
        $this->prepare();
        //gather remote system info
        $this->probe();
    }
    
    function probe()
    {
        $App->out('Gather information about disk layout...');
        # remote disk layout and packages
        if ($_settings['remote']['os'] == "Linux")
        {
            $Cmd->exe("ssh $U@$H '( df -hT ; vgs ; pvs ; lvs ; blkid ; lsblk -fi ; for disk in $(ls /dev/sd[a-z]) ; do fdisk -l \$disk; done )' > $SNAPDIR/incremental/" . $_settings['remote']['host'] . '.' . date('Y-m-d_H.i.s', $App->start_time) . ".poppins.disk-layout.txt 2>&1");
        }
        $App->out('Gather information about packages...');
        switch ($_settings['remote']['distro'])
        {
            case 'Debian':
            case 'Ubuntu':
                $success = $Cmd->exe("ssh $U@$H \"aptitude search '~i !~M' -F '%p' --disable-columns | sort -u\" > $SNAPDIR/incremental/" . $_settings['remote']['host'] . '.' . date('Y-m-d_H.i.s', $App->start_time) . ".poppins.packages.txt", 'passthru');
                if ($success)
                {
                    $App->out('OK');
                }
                else
                {
                    $App->fail('Failed to retrieve package list!');
                }
                break;
            default:
                break;
        }
    }
    
}

class BTRFSBackup extends Backup
{
    
}

class DefaultBackup extends Backup
{
    
}

class ZFSBackup extends Backup
{
    
}