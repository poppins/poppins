<?php
/**
 * File DirectoryStructure.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class DirectoryStructure contains functions used to create required local directories
 */
class DirectoryStructure
{
    // Application class
    protected $Application;

    // Command class
    protected $Cmd;

    // Config class
    protected $Config;

    // Options class - options passed by getopt and ini file
    protected $Options;

    // Session class - session variables
    protected $Session;

    // fs type
    protected $filesystem_type;

    // root dir
    protected $root_dir;

    // rsync dir
    protected $rsync_dir;

    public function __construct($App)
    {
        //Config from ini file
        $this->App = $App;

        //Config from ini file
        $this->Cmd = $this->App->Cmd;

        //Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // App specific settings
        $this->Session = Session::get_instance();

    }

    public function setup_host_dir()
    {
        // host dir
        if ($this->Config->get('local.hostdir-name')) {
            $host_dir_name = $this->Config->get('local.hostdir-name');
        } elseif ($this->Config->get('remote.host')) {
            $host_dir_name = $this->Config->get('remote.host');
        } else {
            $this->fail('Cannot create hostdir! hostdir-name [local] or host [remote] not configured!');
        }

        // set host dir name
        $this->Config->set('local.hostdir-name', $host_dir_name);
        $this->Config->set('local.hostdir', $this->Config->get('local.rootdir') . '/' . $this->Config->get('local.hostdir-name'));

    }

    public function setup_log_dir()
    {
        //check log dir early so we can log stuff
        $log_dir = $this->Config->get('local.logdir');

        //validate dir, create if required
        if (!file_exists($log_dir)) {
            $this->App->out('Create logdir  ' . $log_dir . '...');
            $this->Cmd->exe("mkdir -p " . $log_dir);
            if ($this->Cmd->is_error()) {
                $this->App->fail('Cannot create log dir ' . $log_dir);
            }
        }
    }

    public function setup_root_dir()
    {
        $this->App->out('Check root dir...');

        // root dir
        $this->root_dir = $this->Config->get('local.rootdir');

        //root dir must exist!
        if (!file_exists($this->root_dir)) {
            $this->App->fail("Root dir '" . $this->root_dir . "' does not exist!");
        }

        //check filesystem
        $this->App->out('Check root dir filesystem type...');
        $this->filesystem_type = $this->Cmd->exe("df -T $this->root_dir | tail -1 | tr -s ' ' | cut -d' ' -f2");
        $this->App->out($this->filesystem_type, 'simple-indent');

        // validate filesystem
        $allowed_fs_types = ['ext2', 'ext3', 'ext4', 'btrfs', 'zfs', 'xfs', 'ufs', 'jfs', 'nfs', 'gfs', 'ocfs', 'fuse.osxfs', 'fuse.vmhgfs-fuse'];
        if (!in_array($this->filesystem_type, $allowed_fs_types)) {
            $this->App->fail('Filesystem type of root dir "' . $this->filesystem_type . '"" not supported! Supported: ' . implode('/', $allowed_fs_types));
        }

    }

    public function setup_rsync_sub_dirs()
    {
        // sub dirs
        $dirs = ['meta', 'files', 'restore', 'restore/scripts'];
        if ($this->Config->get('mysql.enabled')) {
            $dirs[] = 'mysql';
        }
        // create directories
        foreach ($dirs as $dir) {
            if (!file_exists($this->rsync_dir . '/' . $dir)) {
                $this->App->out("Create $dir dir $this->rsync_dir/$dir...");
                $this->Cmd->exe("mkdir -p $this->rsync_dir/$dir");

                if ($this->Cmd->is_error()) {
                    $this->App->fail("Failed to create dir $this->rsync_dir/$dir");
                }
            }
        }
    }

}
