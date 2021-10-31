<?php
/**
 * Class DirectoryStructure contains functions used to create required local directories
 */
require_once dirname(__FILE__) . '/DirectoryStructure.php';

class BtrfsDirectoryStructure extends DirectoryStructure
{
    public function setup_host_dir()
    {
        parent::setup_host_dir();

        //check if dir exists
        if (!file_exists($this->Config->get('local.hostdir'))) {
            if ($this->Config->get('local.hostdir-create')) {
                $this->App->out("Directory " . $this->Config->get('local.hostdir') . " does not exist, creating it..");
                $this->Cmd->exe("mkdir -p " . $this->Config->get('local.hostdir'));
                if ($this->Cmd->is_error()) {
                    $this->App->fail("Could not create directory:  " . $this->Config->get('local.hostdir') . "!");
                }
            } else {
                $this->App->fail("Directory " . $this->Config->get('local.hostdir') . " does not exist!");
            }
        }

    }

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
