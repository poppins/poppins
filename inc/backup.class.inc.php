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
 * metadata and MySQL databses.
 */
class Backup
{
    //Application class
    public $App;

    // Config class
    protected $Config;

    // Options class - options passed by getopt and ini file
    protected $Options;

    // Settings class - application specific settings
    protected $Settings;

    //rsyncdir
    protected $rsyncdir;

    /**
     * Backup constructor.
     * 
     * @param $App Application class
     */
    function __construct($App)
    {
        $this->App = $App;

        $this->Cmd = $App->Cmd;
        #####################################
        # CONFIGURATION
        #####################################
        //Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // App specific settings
        $this->Settings = Settings::get_instance();

        $this->rsyncdir = $this->Config->get('local.rsyncdir');
    }

    /**
     * Initialise the class
     */
    function init()
    {
        //validate (check if LOCK file exists)
        $this->validate();
        //pre backup
        $this->jobs();
        //create dirs
        $this->prepare();
        //remote system info
        $this->meta();
        //mysql
        if ($this->Config->get('mysql.enabled'))
        {
            $this->mysql();
        }
        //rsync
        $this->rsync();
    }

    /**
     * Lookup rsync status message
     *
     * @param $rsync_code Rsync error code
     * @return string The message
     */
    function get_rsync_status($rsync_code)
    {
        //list error codes
        $codes = [];
        $codes[0] = 'Success';
        $codes[1] = 'Syntax or usage error';
        $codes[2] = 'Protocol incompatibility';
        $codes[3] = 'Errors selecting input/output files, dirs';
        $codes[4] = 'Requested  action not supported: an attempt was made to manipulate 64-bit files on a platform that cannot support them; or an option was specified that is supported by the client and not by the server.';
        $codes[5] = 'Error starting client-server protocol';
        $codes[6] = 'Daemon unable to append to log-file';
        $codes[10] = 'Error in socket I/O';
        $codes[11] = 'Error in file I/O';
        $codes[12] = 'Error in rsync protocol data stream';
        $codes[13] = 'Errors with program diagnostics';
        $codes[14] = 'Error in IPC code';
        $codes[20] = 'Received SIGUSR1 or SIGINT';
        $codes[21] = 'Some error returned by waitpid()';
        $codes[22] = 'Error allocating core memory buffers';
        $codes[23] = 'Partial transfer due to error';
        $codes[24] = 'Partial transfer due to vanished source files';
        $codes[25] = 'The --max-delete limit stopped deletions';
        $codes[30] = 'Timeout in data send/receive';
        $codes[35] = 'Timeout waiting for daemon connection';
        //message
        $message = '';
        if (is_int($rsync_code) && isset($codes[$rsync_code]))
        {
            $message = $codes[$rsync_code];
        }
        return $message;
    }

    /**
     * Backup remote MySQL databases
     */
    function mysql()
    {
        #####################################
        # MYSQL BACKUPS
        #####################################
        $this->App->out('Mysql backups', 'header');
        //check config directories
        $dirs = [];
        if ($this->Config->get('mysql.configdirs'))
        {
            $dirs = explode(',', $this->Config->get('mysql.configdirs'));
        }
        //assume home dir
        else
        {
            $dirs [] = '~';
        }
        //cache config files
        $cached = [];
        //iterate dirs
        foreach ($dirs as $dir)
        {
            $output = false;
            //check if allowed
            $this->Cmd->exe("'cd $dir' 2>&1", true);
            if ($this->Cmd->is_error())
            {
                $this->App->warn('Cannot access remote dir ' . $dir . '...');
            }
            else
            {
                $output = $this->Cmd->exe("'cd $dir;ls .my.cnf* 2>/dev/null'", true);
            }
            //check output
            if ($output)
            {
                $configfiles = explode("\n", $output);
            }
            else
            {
                $configfiles = [];
                $this->App->warn('Cannot find mysql config files in remote dir ' . $dir . '...');
                continue;
            }
            if (count($configfiles))
            {
                //iterate config files
                foreach ($configfiles as $configfile)
                {
                    //instance
                    $instance = preg_replace('/^.+my\.cnf(\.)?/', '', $configfile);
                    $instance = ($instance) ? $instance : 'default';

                    //ignore if file is the same
                    $contents = $this->Cmd->exe("'cd $dir;cat .my.cnf*'", true);
                    if (in_array($contents, $cached))
                    {
                        $this->App->warn("Found duplicate mysql config file $dir/$configfile...");
                        continue;
                    }
                    else
                    {
                        $cached [] = $contents;
                    }

                    $this->App->out("Backup databases from $dir/$configfile");
                    $instancedir = "$this->rsyncdir/mysql/$instance";
                    // check if dir exists
                    if (!is_dir($instancedir))
                    {
                        $this->App->out("Create directory $instancedir...");
                        $this->Cmd->exe("mkdir -p $instancedir");
                    }
                    //get all dbs
                    $dbs = $this->Cmd->exe("'mysql --defaults-file=\"$dir/$configfile\" --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'", true);
                    foreach (explode("\n", $dbs) as $db)
                    {
                        if (empty($db))
                        {
                            continue;
                        }
                        $this->Cmd->exe("'mysqldump --defaults-file=$dir/$configfile --ignore-table=mysql.event --routines --single-transaction --quick --databases $db' | gzip > $instancedir/$db.sql.gz", true);
                        if (!$this->Cmd->is_error())
                        {
                            $this->App->out("$db... OK.", 'indent');
                        }
                        else
                        {
                            $this->App->fail("MySQL backup failed!");
                        }
                    }
                }
            }
        }
    }

    /**
     * Execute remote jobs/scripts before backups
     */
    function jobs()
    {
        #####################################
        # PRE BACKUP JOBS
        #####################################
        // do our thing on the remote end.
        $this->App->out('PRE BACKUP JOB', 'header');
        //check if jobs
        if ($this->Config->get('remote.pre-backup-script'))
        {
            $this->App->out('Remote script configured, validating...');
            $script = $this->Config->get('remote.pre-backup-script');
            //test if the script exists
            $this->Cmd->exe("'test -x $script'", true);
            if ($this->Cmd->is_error())
            {
                $message = 'Remote script is not an executable script!';
                if ($this->Config->get('remote.pre-backup-onfail') == 'abort')
                {
                    $this->App->fail($message);
                }
                else
                {
                    $this->App->warn($message);
                }
            }
            //run remote command
            $this->App->out('Running remote script...');
            $output = $this->Cmd->exe("'$script 2>&1'", true);
            $this->App->out('Output:');
            $this->App->out();
            $this->App->out($output);
            $this->App->out();
            if ($this->Cmd->is_error())
            {
                $message = 'Remote script did not run successfully!';
                if ($this->Config->get('remote.pre-backup-onfail') == 'abort')
                {
                    $this->App->fail($message);
                }
                else
                {
                    $this->App->warn($message);
                }
            }
            else
            {
                $this->App->out('Remote job done... (' . date('Y-m-d H:i:s') . ')');
            }
        }
        else
        {
            $this->App->out('No pre backup script defined...');
        }
    }

    /**
     * Gather metadata about remote installation such as disk and packages
     */
    function meta()
    {
        //variables
        $filebase = $this->Settings->get('meta.filebase');
        $this->App->out('meta data', 'header');
        //disk layout
        if ($this->Config->get('meta.remote-disk-layout'))
        {
            $this->App->out('Gather information about disk layout...');
            // remote disk layout and packages
            if ($this->Config->get('remote.os') == "Linux")
            {
                $this->Cmd->exe("'( df -hT 2>&1; vgs 2>&1; pvs 2>&1; lvs 2>&1; blkid 2>&1; lsblk -fi 2>&1; for disk in $(ls /dev/sd[a-z] /dev/cciss/* 2>/dev/null) ; do fdisk -l \$disk 2>&1; done )' > $this->rsyncdir/meta/" . $filebase . ".disk-layout.txt", true);

                if ($this->Cmd->is_error())
                {
                    $this->App->warn('Failed to gather information about disk layout!');
                }
                else
                {
                    $this->App->out("Write to file $this->rsyncdir/meta/" . $filebase . ".disk-layout.txt...");
                    $this->App->out("OK!", 'simple-success');
                }
            }
        }
        else
        {
            $this->App->out('Skipping information about disk layout...');
        }
        $this->App->out();
        //packages
        if ($this->Config->get('meta.remote-package-list'))
        {
            $this->App->out('Gather information about packages...');
            $packages = [];
            switch ($this->Config->get('remote.distro'))
            {
                case 'Debian':
                case 'Ubuntu':
                    $packages['aptitude --version'] = "aptitude search \"~i !~M\" -F \"%p\" --disable-columns | sort -u";
                    $packages['dpkg --version'] = "dpkg --get-selections";
                    break;
                case 'Red Hat':
                case 'CentOS':
                case 'Fedora':
                    $packages['yumdb --version'] =  "yumdb search reason user | sort | grep -v \"reason = user\" | sed '/^$/d'";
                    $packages['rpm --version'] =  "rpm -qa";
                    break;
                case 'Arch':
                case 'Manjaro':
                    $packages['pacman --version'] =  "pacman -Qet";
                    break;
                default:
                    $this->App->out('Remote OS not supported.');
                    break;
            }
            //retrieve packge list
            $c = count($packages);
            $i = 1;
            foreach ($packages as $validation => $execution)
            {
                $this->Cmd->exe("'$validation' 2>&1", true);
                if ($this->Cmd->is_error())
                {
                    //no more commands to execute, fail
                    if($i == $c)
                    {
                        $this->App->fail('Failed to retrieve package list! Remote package manager(s) not installed?');
                    }
                }
                else
                {
                    $this->Cmd->exe("'$execution' > $this->rsyncdir/meta/" . $filebase . ".packages.txt", true);
                    //possibly sed, grep or sort not installed?
                    if ($this->Cmd->is_error())
                    {
                        //no more commands to execute, fail
                        if ($i == $c)
                        {
                            $this->App->fail('Failed to retrieve package list! Cannot execute command!');
                        }
                        else
                        {
                            //warn???
                            continue;
                        }
                    }
                    //success, break!
                    else
                    {
                        $arr = explode(' ',trim($validation));
                        $pkg_mngr = $arr[0];
                        $this->App->out("Using the $pkg_mngr package manager. Write to file $this->rsyncdir/meta/" . $filebase . ".packages.txt...");
                        $this->App->out("OK!", 'simple-success');
                        break;
                    }
                }
                $i++;
            }
        }
        else
        {
            $this->App->out('Skipping information about packages...');
        }
    }

    /**
     * Prepare backups
     */
    function prepare()
    {
        #####################################
        # SYNC DIR
        #####################################
        if (!file_exists($this->rsyncdir))
        {
            $this->App->out("Create sync dir $this->rsyncdir...");
            $this->create_syncdir();
        }
        #####################################
        # OTHER DIRS
        #####################################
        $a = ['meta', 'files'];
        if ($this->Config->get('mysql.enabled'))
        {
            $a [] = 'mysql';
        }
        foreach ($a as $aa)
        {
            if (!file_exists($this->rsyncdir . '/' . $aa))
            {
                $this->App->out("Create $aa dir $this->rsyncdir/$aa...");
                $this->Cmd->exe("mkdir -p $this->rsyncdir/$aa");
            }
        }
    }

    /**
     * Rsync remote files and directories
     */
    function rsync()
    {
        //rsync backups
        $this->App->out('Rsync directories', 'header');
        #####################################
        # RSYNC OPTIONS
        #####################################
        //options
        $o = [];
        $o [] = "--delete-excluded --delete --numeric-ids";

        //ssh
        if ($this->Config->get('remote.ssh'))
        {
            $ssh = $this->Cmd->parse('{SSH}');
            $o [] = '-e "' . $ssh . ' -o TCPKeepAlive=yes -o ServerAliveInterval=30"';
        }

        // general options
        if ($this->Config->get('rsync.verbose'))
        {
            $o [] = "-v";
        }
        if ($this->Config->get('rsync.hardlinks'))
        {
            $o [] = "-H";
        }
        if (in_array((integer) $this->Config->get('rsync.compresslevel'), range(1, 9)))
        {
            $o [] = "-z --compress-level=" . $this->Config->get('rsync.compresslevel');
        }
        // rewrite as little blocks as possible. do not set this for default!
        if (in_array($this->Config->get('local.snapshot-backend'), ['zfs', 'btrfs']))
        {
            $o [] = "--inplace";
        }
        $rsync_options = implode(' ', $o);
        #####################################
        # RSYNC DIRECTORIES
        #####################################
        foreach ($this->Config->get('included') as $source => $target)
        {
            //exclude dirs
            $excluded = [];
            if ($this->Config->get(['excluded', $source]))
            {
                $exludedirs = explode(',', $this->Config->get(['excluded', $source]));

                foreach ($exludedirs as $d)
                {
                    $excluded [] = "--exclude=$d";
                }
            }
            //excluded files
            $excluded = implode(' ', $excluded);
            //output command
            $this->App->out("rsync '$source' @ " . date('Y-m-d H:i:s') . "...", 'indent');
            if (!is_dir("$this->rsyncdir/files/$target"))
            {
                $this->App->out("Create target dir $this->rsyncdir/files/$target...");
                $this->Cmd->exe("mkdir -p $this->rsyncdir/files/$target");
            }
            //check trailing slash
            $sourcedir = (preg_match('/\/$/', $source)) ? $source : "$source/";
            $targetdir = "$this->rsyncdir/files/$target/";
            //slashes are protected by -s option in rsync
            $sourcedir = stripslashes($sourcedir);
            $targetdir = stripslashes($targetdir);
            $remote_connection = ($this->Config->get('remote.ssh'))? $this->Config->get('remote.user') . "@" . $this->Config->get('remote.host') .':':'';
            $cmd = "rsync $rsync_options -xas $excluded " .$remote_connection. "\"$sourcedir\" '$targetdir' 2>&1";
            $this->App->out($cmd);
            //obviously try rsync at least once :)
            $attempts = 1;
            //retry attempts on rsync fail
            if ($this->Config->get('rsync.retry-count'))
            {
                $attempts += (integer) $this->Config->get('rsync.retry-count');
            }
            //retry timeout between attempts
            $timeout = 0;
            if ($this->Config->get('rsync.retry-timeout'))
            {
                $timeout += (integer) $this->Config->get('rsync.retry-timeout');
            }
            $i = 1;
            $success = false;
            while ($i <= $attempts)
            {
                $output = $this->Cmd->exe("$cmd");
                $this->App->out($output);
                //WARNINGS - allow some rsync errors to occur
                if (in_array($this->Cmd->exit_status, [24]))
                {
                    $message = $this->get_rsync_status($this->Cmd->exit_status);
                    $message = (empty($message)) ? '' : ': "' . $message . '".';
                    $this->App->warn("Rsync of $sourcedir directory exited with a non-zero status! Non fatal, will continue. Exit status: " . $this->Cmd->exit_status . $message);
                    $success = true;
                    break;
                }
                //ERRORS
                elseif ($this->Cmd->exit_status != 0)
                {
                    $message = $this->get_rsync_status($this->Cmd->exit_status);
                    $message = (empty($message)) ? '' : ': "' . $message . '".';
                    $this->App->warn("Rsync of $sourcedir directory attempt $i/$attempts exited with a non-zero status! Fatal, will abort. Exit status " . $this->Cmd->exit_status . $message);
                    $message = [];
                    if ($i != $attempts)
                    {
                        $message [] = "Will retry rsync attempt " . ($i + 1) . " of $attempts in $timeout second(s)...\n";
                        sleep($timeout);
                    }
                    $this->App->out(implode(' ', $message));
                    $i++;
                }
                //SUCCESS
                else
                {
                    $this->App->out("");
                    $success = true;
                    break;
                }
            }
            //check if successful
            if (!$success)
            {
                $message = $this->get_rsync_status($this->Cmd->exit_status);
                $message = (empty($message)) ? '' : ': "' . $message . '".';
                $this->App->fail("Rsync of $sourcedir directory failed! Aborting! Exit status " . $this->Cmd->exit_status . $message);
            }
        }
        $this->App->out("OK!", 'simple-success');
    }

    /**
     * Check if LOCK file exists
     */
    function validate()
    {
        #####################################
        # CREATE LOCK FILE
        #####################################
        # check for lock
        if (file_exists($this->Config->get('local.hostdir') . "/LOCK"))
        {
            $this->App->fail("LOCK file " . $this->Config->get('local.hostdir') . "/LOCK exists!", 'LOCKED');
        }
        else
        {
            $this->App->out('Create LOCK file...');
            $this->Cmd->exe("touch " . $this->Config->get('local.hostdir') . "/LOCK");
        }
    }

}

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

/**
 * Class ZfsBackup based on zfs filesystem (zfs snapshots)
 */
class ZfsBackup extends Backup
{

    /**
     * Create the syncdir
     */
    function create_syncdir()
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $this->Cmd->exe("zfs create " . $rsyncdir);
    }

}
