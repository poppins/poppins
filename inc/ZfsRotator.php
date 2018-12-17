<?php

/**
 * File ZFSRotator.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/Rotator.php';


/**
 * Class Rotator contains functions that will handle rotation based on hardlinks,
 * zfs or btrfs snapshots
 */

class ZfsRotator extends Rotator
{
    /**
     * Creates a command to add a snapshot dir to a parent directory
     *
     * @param $dir The snapshot directory
     * @param $parent The parent directory
     * @return string The command
     */
    function add($dir, $parent)
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $cmd = "zfs snapshot $rsyncdir@$parent-$dir";
        $this->App->out("Create zfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Wrap up the action
     */
    function finalize()
    {
        //create a symlink to .zfs
        if(file_exists($this->rsyncdir.'/.zfs/snapshot') && !file_exists($this->Config->get('local.hostdir').'/archive'))
        {
            $this->App->out("Create an archive dir symlink to zfs snapshots...");
            $cmd = 'ln -s '.$this->rsyncdir.'/.zfs/snapshot '.$this->Config->get('local.hostdir').'/archive';
            $this->Cmd->exe("$cmd");

        }
    }

    /**
     * Creates a command to remove a snapshot from a directory
     *
     * @param $dir The snapshot directory
     * @param $parent  The parent directory
     * @return string The command
     */
    function remove($dir, $parent)
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $cmd = "zfs destroy $rsyncdir@$parent-$dir";
        $this->App->out("Remove zfs snapshot: $cmd");
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Prepare the rotation
     * Check archive dir
     */
    function create()
    {
        $this->App->out('No archive directories to create..');
    }

}
