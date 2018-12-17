<?php

/**
 * File DefaultBackup.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

require_once dirname(__FILE__).'/Backup.php';

/**
 * Class DefaultBackup based on default filesystem (hardlink rotation)
 */
class DefaultBackup extends Backup
{

    /**
     * Create the syncdir
     */
    function create_syncdir()
    {
        $this->Cmd->exe("mkdir -p " . $this->rsyncdir);
    }

}