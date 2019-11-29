<?php
/**
 * File backup.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class Backup contains functions used to backup files and directories,
 * metadata and mysql databses.
 */
require_once dirname(__FILE__).'/Backup.php';

/**
* Class ZfsBackup based on zfs filesystem (zfs snapshots)
*/
class ZfsBackup extends Backup
{

}