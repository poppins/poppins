<?php

/**
 * File BtrfsRotator.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__) . '/Rotator.php';

/**
 * Class Rotator contains functions that will handle rotation based on hardlinks,
 * zfs or btrfs snapshots
 */
class BtrfsRotator extends Rotator
{
    /**
     * Creates a command to add a snapshot dir to a parent directory
     *
     * @param $dir The snapshot directory
     * @param $parent The parent directory
     * @return string The command
     */
    public function add($dir, $parent)
    {
        $cmd = "btrfs subvolume snapshot -r $this->rsync_dir " . $this->archive_dir . "/$parent/$dir";
        $this->App->out("Create btrfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Creates a command to remove a snapshot from a directory
     *
     * @param $snapshot The snapshot directory
     * @param $type  The type of snapshot: 2-minutely, 1-hourly, etc...
     * @return string The command
     */
    public function remove($snapshot, $type)
    {
        $cmd = "btrfs subvolume delete " . $this->archive_dir . "/$type/$snapshot";
        $this->App->out("Remove btrfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }
}
