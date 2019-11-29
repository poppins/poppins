<?php
/**
 * Class DirectoryStructure contains functions used to create required local directories
 */
require_once dirname(__FILE__).'/DirectoryStructure.php';

class ZfsDirectoryStructure extends DirectoryStructure
{

    function setup_host_dir()
    {
        parent::setup_host_dir();

        $host_dir_check = $this->Cmd->exe("zfs get -H -o value mountpoint " . $this->Config->get('local.hostdir'));
        if ($host_dir_check != $this->Config->get('local.hostdir'))
        {
            if ($this->Config->get('local.hostdir-create'))
            {
                $zfs_fs = preg_replace('/^\//', '', $this->Config->get('local.hostdir'));
                $this->App->out("zfs filesystem " . $zfs_fs . " does not exist, creating zfs filesystem..");
                $this->Cmd->exe("zfs create " . $zfs_fs);
                if ($this->Cmd->is_error())
                {
                    $this->App->fail("Could not create zfs filesystem:  " . $zfs_fs . "!");
                }
            }
            else
            {
                $this->App->fail("Directory " . $this->Config->get('local.hostdir') . " does not exist! Directive not set to create it (no)..");
            }
        }
        //validate if dataset name and mountpoint are the same
        $zfs_info = $this->Cmd->exe("zfs list | grep '".$this->Config->get('local.hostdir')."$'");
        $a = explode(' ', $zfs_info);
        if ('/' . reset($a) != end($a))
        {
            $this->App->fail('zfs name and mountpoint do not match!');
        }

    }

    function setup_rsync_dir()
    {
        $rsync_dir_name = 'rsync.zfs';
        $this->rsync_dir = $this->Config->get('local.hostdir').'/'.$rsync_dir_name;

        // check if exists
        if (!file_exists($this->rsync_dir))
        {
//            $this->App->out("Create sync dir $this->rsync_dir...");
//            $this->Cmd->exe("mkdir -p " . $this->rsync_dir);

            $this->App->out("Create snapshot $this->rsync_dir...");
            $rsync_dir = preg_replace('/^\//', '', $this->rsync_dir);
            $this->Cmd->exe("zfs create " . $rsync_dir);
        }

        $this->Config->set('local.rsync_dir',  $this->rsync_dir);
    }

    function setup_root_dir()
    {
        parent::setup_root_dir();

        // validate filesystem
        $snapshots_backend = $this->Config->get('local.snapshot-backend');
        
        if($this->filesystem_type != $snapshots_backend)
        {
            $this->App->fail('Rootdir is not a '.$snapshots_backend.' filesystem!');
        }

        //if using zfs, we want a mount point
        $root_dir_check = $this->Cmd->exe("zfs get -H -o value mountpoint ".$this->root_dir);
        if($root_dir_check != $this->root_dir)
        {
            $this->App->fail("No zfs mount point " . $this->root_dir . " found!");
        }
        //validate if dataset name and mountpoint are the same
        $zfs_info = $this->Cmd->exe("zfs list | grep '".$this->root_dir."$'");
        $a = explode(' ', $zfs_info);
        if('/'.reset($a) != end($a))
        {
            $this->App->fail('zfs name and mountpoint do not match!');
        }
    }

}