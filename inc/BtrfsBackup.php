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
     * Create the syncdir
     */
    function create_syncdir()
    {
        $this->Cmd->exe("btrfs subvolume create " . $this->rsyncdir);
    }

}