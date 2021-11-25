<?php
/**
 * Class DirectoryStructure contains functions used to create required local directories
 */
require_once dirname(__FILE__) . '/DirectoryStructure.php';

class ZfsDirectoryStructure extends DirectoryStructure
{

    public function setup_host_dir()
    {
        parent::setup_host_dir();

        $host_dir_name = $this->Config->get('local.hostdir-name');
        $zfs_snapshot_base_path = $this->App->Session->get('zfs.snapshot_base_path');
        $zfs_snapshot_host_path = $zfs_snapshot_base_path . '/' . $host_dir_name;
        $this->App->out("ZFS host path is '$zfs_snapshot_base_path'...");
        $this->App->Session->set('zfs.snapshot_host_path', $zfs_snapshot_host_path);

        $host_dir_check = $this->Cmd->exe("zfs get -H -o value mountpoint " . $this->Config->get('local.hostdir'));
        if ($host_dir_check != $this->Config->get('local.hostdir')) {
            $this->App->out("zfs filesystem " . $zfs_snapshot_host_path . " does not exist, creating zfs filesystem..");
            $this->Cmd->exe("zfs create " . $zfs_snapshot_host_path);
            if ($this->Cmd->is_error()) {
                $this->App->fail("Could not create zfs filesystem:  " . $zfs_snapshot_host_path . "!");
            }
        }
    }

    public function setup_rsync_dir()
    {
        $rsync_dir_name = 'rsync.zfs';
        $zfs_snapshot_host_path = $this->App->Session->get('zfs.snapshot_host_path');

        $this->rsync_dir = $this->Config->get('local.hostdir') . '/' . $rsync_dir_name;

        // check if exists
        if (!file_exists($this->rsync_dir)) {
            $this->App->out("Create sync dir $this->rsync_dir...");
            $this->Cmd->exe("mkdir -p " . $this->rsync_dir);

            $this->App->out("Create snapshot $zfs_snapshot_host_path/$rsync_dir_name...");
            $this->Cmd->exe("zfs create " . $zfs_snapshot_host_path . '/' . $rsync_dir_name);
        }

        $this->Config->set('local.rsync_dir', $this->rsync_dir);
    }

    public function setup_root_dir()
    {
        parent::setup_root_dir();

        // validate filesystem
        $snapshots_backend = $this->Config->get('local.snapshot-backend');

        if ($this->filesystem_type != $snapshots_backend) {
            $this->App->fail('Rootdir is not a ' . $snapshots_backend . ' filesystem!');
        }

        //if using zfs, we want a mount point
        $root_dir_check = $this->Cmd->exe("zfs get -H -o value mountpoint " . $this->root_dir);
        if ($root_dir_check != $this->root_dir) {
            $this->App->fail("No zfs mount point " . $this->root_dir . " found. Create it first!");
        }
        // TODO SET prefix zpool???
        //validate if dataset name and mountpoint are the same
        $zfs_info = $this->Cmd->exe("zfs list | grep ' " . $this->root_dir . "$'");
        $a = explode(' ', $zfs_info);
        $zfs_snapshot_base_path = $a[0];
        $this->App->out("ZFS base path is '$zfs_snapshot_base_path'...");
        $this->App->Session->set('zfs.snapshot_base_path', $zfs_snapshot_base_path);
    }

}
