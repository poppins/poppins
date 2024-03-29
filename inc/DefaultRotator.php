<?php

/**
 * File DefaultRotator.php
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
class DefaultRotator extends Rotator
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
        // check permissions
        if (!is_writable($this->archive_dir . "/$parent")) {
            $this->App->fail('Cannot add snapshot! Archive dir ' . $dir . ' is not writable!');
        }

        if (!is_executable($this->archive_dir . "/$parent")) {
            $this->App->fail('Cannot add snapshot! Archive dir ' . $dir . ' is not executable!');
        }

        $cmd = "{CP} -la $this->rsync_dir " . $this->archive_dir . "/$parent/$dir";
        $this->App->out('Create hardlink copy: ' . $this->Cmd->parse($cmd));
        return $this->Cmd->exe("$cmd");
    }

    /**
     * Creates a command to remove a snapshot from a directory
     *
     * @param $snapshot The snapshot directory
     * @param $type  The snapshot type
     * @return string The command
     */
    public function remove($snapshot, $type)
    {
        // take precautions when executing an rm command!
        foreach ([$this->archive_dir, $snapshot, $type] as $variable) {
            $variable = trim($variable);
            if (!$variable || empty($variable) || $variable == '' || preg_match('/^\/+$/', $variable)) {
                $this->App->fail('Cannot execute a rm command as a variable is empty!');
            }
        }
        $cmd = "{RM} -rf " . $this->archive_dir . "/$type/$snapshot";
        $this->App->out('Remove direcory: ' . $this->Cmd->parse($cmd));
        return $this->Cmd->exe("$cmd");
    }
}
