<?php
/**
 * Class DirectoryStructure contains functions used to create required local directories
 */
require_once dirname(__FILE__) . '/DirectoryStructure.php';

class BtrfsDirectoryStructure extends DirectoryStructure
{

    public function setup_rsync_dir()
    {
        $rsync_dir_name = 'rsync.btrfs';
        $this->rsync_dir = $this->Config->get('local.hostdir') . '/' . $rsync_dir_name;

        // check if exists
        if (!file_exists($this->rsync_dir)) {
            $this->App->out("Create snapshot $this->rsync_dir...");
            $this->Cmd->exe("btrfs subvolume create " . $this->rsync_dir);
        }

        $this->Config->set('local.rsync_dir', $this->rsync_dir);
    }

    public function setup_root_dir()
    {
        parent::setup_root_dir();

        // set backend
        $snapshots_backend = $this->Config->get('local.snapshot-backend');

        // validate filesystem
        if ($this->filesystem_type != $snapshots_backend) {
            $this->App->fail('Rootdir is not a ' . $snapshots_backend . ' filesystem!');
        }

    }

}
