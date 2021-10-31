<?php
/**
 * Class Backup contains functions used to backup files and directories,
 * metadata and mysql databses.
 */
require_once dirname(__FILE__) . '/Backup.php';

/**
 * Class BtrfsBackup based on btrfs filesystem (btrfs snapshots)
 */
class BtrfsBackup extends Backup
{

    /**
     * Validate sync dir
     */
    public function validate_sync_dirs()
    {
        parent::validate_sync_dirs();

        // check if rsync directory is part of btrfs file system
        $rsync_dir_stripped = str_replace($this->Config->get('local.rootdir'), '', $this->rsync_dir);
        $rsync_dir_stripped = ltrim($rsync_dir_stripped, "/");

        $cmd = "btrfs subvolume list $this->rsync_dir | grep " . $rsync_dir_stripped . "$";

        $output = $this->Cmd->exe($cmd);

        if ($output == '') {
            $this->App->fail("Rsync dir is not a subvolume!");
        }

    }

}
