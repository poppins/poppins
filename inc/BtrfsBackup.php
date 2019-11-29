<?php
/**
 * Class Backup contains functions used to backup files and directories,
 * metadata and mysql databses.
 */
require_once dirname(__FILE__).'/Backup.php';

/**
 * Class BtrfsBackup based on btrfs filesystem (btrfs snapshots)
 */
class BtrfsBackup extends Backup
{

    /**
     * Validate sync dir
     */
    function validate_sync_dir()
    {
        parent::validate_sync_dir();

        // check all btrfs subvolumes
        $output = $this->Cmd->exe('btrfs subvolume list -o / | grep '.trim($this->rsync_dir, '/'));

        if ($output == '')
        {
            $this->App->fail("Rsync dir is not a subvolume!");
        }

    }

}