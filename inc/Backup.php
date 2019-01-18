<?php
/**
 * File Backup.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class Backup contains functions used to backup files and directories,
 * metadata and mysql databses.
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

    //rsync
    protected $rsyncdir;
    protected $rsync_success = false;

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
        $this->Session = Session::get_instance();

        $this->rsyncdir = $this->Config->get('local.rsyncdir');
    }

    /**
     * Initialise the class
     */
    function init()
    {
        // create dirs and pre backup job
        $this->prepare();

        // remote system info
        $this->meta();
        // mysql
        if ($this->Config->get('mysql.enabled'))
        {
            $this->databases();
        }
        // rsync
        $this->rsync();
        // post backup job
        $this->wrap_up();
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
     * Backup remote mysql databases
     */
    function databases()
    {
        #####################################
        # MYSQL BACKUPS
        #####################################
        $this->App->out('Mysql backups', 'header');
        // dry run?
        if($this->Options->is_set('n'))
        {
            $this->App->out('DRY RUN!');
            $this->App->out();
        }
        // output types
        $mysql_output = explode(',',$this->Config->get('mysql.output'));
        //iterate config files
        foreach ($this->Session->get('mysql.configfiles') as $config_file)
        {
            $mysqldump_commands = [];
            //get database and tabledump commands
            foreach($mysql_output as $object)
            {
                $this->App->out("Prepare MySQL $object statements for $config_file...");
                $classname = ucfirst($object).'Dumper';
                $dumper = new $classname($this->App, $config_file);
                $mysqldump_commands = array_merge($mysqldump_commands, $dumper->get_commands());
            }
            $this->App->out();
            $this->App->out('OK!');
            $this->App->out();
            #####################################
            # EXECUTE MYSQLDUMP COMMANDS
            #####################################
            // dry run
            if($this->Options->is_set('n'))
            {
                $this->App->out("List MySQL statements (dry run)...");
            }
            else
            {
                $this->App->out("Execute MySQL statements...");
            }
            $this->App->out();
            // mark time
            $this->Session->set('chrono.mysql.start',date('U'));
            // loop thru all the commands
            foreach ($mysqldump_commands as $key => $cmd)
            {
                // dry run
                if($this->Options->is_set('n'))
                {
                    $this->App->out($cmd);
                }
                else
                {
                    $this->Cmd->exe($cmd, true);
                    if (!$this->Cmd->is_error())
                    {
                        $this->App->out("$key... OK.", 'indent');
                    }
                    else
                    {
                        $this->App->fail("mysql backup failed! Command: " . $cmd);
                    }
                }
            }
            // mark time
            $this->Session->set('chrono.mysql.stop',date('U'));
        }
    }

    /**
     * Execute remote jobs/scripts before/after backups
     * @param string $type Pre or Post backup job
     */
    function jobs($type = 'pre')
    {
        #####################################
        # PRE BACKUP JOBS
        #####################################
        // do our thing on the remote end.
        $this->App->out($type.' backup script', 'header');
        // dry run?
        if($this->Options->is_set('n'))
        {
            $this->App->out('DRY RUN!');
            return;
        }
        //check if jobs
        if ($this->Config->get('remote.'.$type.'-backup-script'))
        {
            $this->App->out('Remote script configured, validating...');
            $script = $this->Config->get('remote.'.$type.'-backup-script');
            //test if the script exists
            $this->Cmd->exe("'test -x $script'", true);
            if ($this->Cmd->is_error())
            {
                $message = 'Remote '.$type.'-backup script is not an executable script!';
                if ($this->Config->get('remote.'.$type.'-backup-onfail') == 'abort')
                {
                    $this->App->fail($message);
                }
                else
                {
                    $this->App->warn($message);
                }
            }
            // mark time
            $this->Session->set('chrono.'.$type.'-backup-script.start', date('U'));
            //run remote command
            $this->App->out('Running remote script...');
            $output = $this->Cmd->exe("'$script 2>&1'", true);
            $this->App->out('Output:');
            $this->App->out();
            $this->App->out($output);
            $this->App->out();
            if ($this->Cmd->is_error())
            {
                $message = 'Remote '.$type.'-backup script did not run successfully!';
                if ($this->Config->get('remote.'.$type.'-backup-onfail') == 'abort')
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
                $this->App->out();
                $this->App->out("OK!", 'simple-success');
                // mark time
                $this->Session->set('chrono.'.$type.'-backup-script.stop', date('U'));
            }
        }
        else
        {
            $this->App->out('No '.$type.' backup script defined...');
        }
    }

    /**
     * Gather metadata about remote installation such as disk and packages
     */
    function meta()
    {
        //variables
        $filebase = $this->Session->get('meta.filebase');
        $this->App->out('Metadata', 'header');
        // dry run?
        if($this->Options->is_set('n'))
        {
            $this->App->out('DRY RUN!');
            return;
        }
        // set paths
        $this->Session->set('meta.path', $this->rsyncdir . '/meta/');
        $this->Session->set('restore.path', $this->rsyncdir . '/restore/');
        $this->Session->set('scripts.path', $this->rsyncdir . '/restore/scripts/');
        $this->Session->set('restore.script.local', $this->rsyncdir . '/restore/'.$filebase.'.restore.sh');
        // other variables
        $ssh_connection = ($this->Config->get('remote.ssh'))? $this->Config->get('remote.user') . "@" . $this->Config->get('remote.host') :'';
        $rsync_seperator = ($this->Config->get('remote.ssh'))? ':':'';
        $tee_cmd = "tee -a ".$this->Session->get('restore.script.local');
        #####################################
        # DISK LAYOUT
        #####################################
        if ($this->Config->get('meta.remote-disk-layout'))
        {
            // need root privileges
            if($this->Config->get('remote.user') == 'root')
            {
                $this->App->out('Gather information about disk layout...');
                // remote disk layout and packages
                if ($this->Config->get('remote.os') == "Linux")
                {
                    // disk layout commands
                    $cmds = [];
                    $cmds [] = 'df -hT';
                    $cmds [] = 'vgs';
                    $cmds [] = 'pvs';
                    $cmds [] = 'lvs';
                    $cmds [] = 'blkid';
                    $cmds [] = 'lsblk -fi';

                    $emb = '%%%%%%';

                    $cmd_string = '';
                    foreach ($cmds as $cmd)
                    {
                        $cmd_string .= "echo " . $emb . ' ' . $cmd . ' ' . $emb . "; echo; $cmd 2>&1; echo;";
                    }

                    $this->Cmd->exe("'( " . $cmd_string . " echo  " . $emb . " fdisk " . $emb . "; for disk in $(ls /dev/sd[a-z] 2>/dev/null) ; do fdisk -l \$disk 2>&1 && echo; done )' > $this->rsyncdir/meta/" . $filebase . ".disk_layout.txt", true);

                    if ($this->Cmd->is_error())
                    {
                        $this->App->warn('Failed to gather information about disk layout!');
                    }
                    else
                    {
                        $this->App->out("Write to file " . $this->Session->get('meta.path') . $filebase . ".disk_layout.txt...");
                        $this->App->out();
                        $this->App->out("OK!", 'simple-success');
                    }
                }
            }
            else
            {
                $this->App->warn('Cannot get disk layout. Remote user is "'. $this->Config->get('remote.user') .'". Must be root!');
            }
        }
        else
        {
            $this->App->out('Skip information about disk layout...');
        }
        $this->App->out();
        #####################################
        # PACKAGES
        #####################################
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
                    $this->Cmd->exe("'$execution' > ".$this->Session->get('meta.path') . $filebase . ".packages.txt", true);
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
                        $this->App->out("Using the $pkg_mngr package manager. Write to file ".$this->Session->get('meta.path') . $filebase . ".packages.txt...");
                        $this->App->out();
                        $this->App->out("OK!", 'simple-success');
                        break;
                    }
                }
                $i++;
            }
        }
        else
        {
            $this->App->out('Skip information about packages...');
        }
        #####################################
        # RESTORE SCRIPTS
        #####################################
        if ($this->Config->get('meta.restore-scripts'))
        {
            if($this->Config->get('remote.user') == 'root')
            {
                $content = [];
                $content [] = '';
                $content [] = '# WARNING! Use at your own risk. Do not blindly copy paste and run these scripts.';
                $content [] = '';
                $this->Cmd->exe("echo '" . implode("\n", $content) . "' >> " . $this->Session->get('restore.script.local'));
                // rsync data from backup server
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '# RSYNC RESTORE DIRECTORIES' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $content = [];
                $content [] = '# Rsync both the meta and restore directories to the rescue system.';
                $content [] = 'rsync -av ' . $this->rsyncdir . '/meta ' . $this->rsyncdir . '/restore ' . $ssh_connection . $rsync_seperator . "'/tmp'";
                $this->Cmd->exe("echo '" . implode("\n", $content) . "' >> " . $this->Session->get('restore.script.local'));
                #####################################
                # BACKUP PARTITION TABLE
                #####################################
                //restore
                $restore_type = 'partitions_restore';
                $this->Cmd->exe("echo >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '# " . strtoupper(str_replace('_', ' ', $restore_type)) . "' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $content = [];
                $content [] = '# WARNING! The order of partitions may not be the same as the original configuration.';
                $content [] = '';
                $content [] = '# To restore, run on backup server: ';
                $content [] = 'ssh ' . $ssh_connection . ' "bash /tmp/restore/scripts/' . $restore_type . '.*.sh"';
                $content [] = '';
                $content [] = '--- OR ---';
                $content [] = '';
                $content [] = '# Or run manually on the rescue system:';
                $content [] = '';
                $this->Cmd->exe("echo '" . implode("\n", $content) . "' >> " . $this->Session->get('restore.script.local'));
                // iterate disks
                $drives = $this->Cmd->exe("'for disk in $(ls /dev/sd[a-z]); do echo \$disk; done'", true);
                if (!empty($drives))
                {
                    foreach (explode("\n", $drives) as $drive)
                    {
                        $filename_drive = str_replace('/', '_', trim($drive, '/'));
                        $this->Cmd->exe("'( sfdisk -d " . $drive . " 2>/dev/null)' > " . $this->Session->get('meta.path') . "$filebase.partition.$filename_drive.txt", true);
                        //restore commands
                        $filebase = $this->Session->get('meta.filebase');
                        // add commet to following line
                        $this->Cmd->exe("echo -n '# ' | $tee_cmd > " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.' . $filename_drive . '.sh');
                        $this->Cmd->exe("fdisk -l " . $drive . " 2>/dev/null | head -n1 | $tee_cmd > " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.' . $filename_drive . '.sh');
                        $this->Cmd->exe("echo 'sfdisk -f " . $drive . " < /tmp/meta/$filebase.partition.$filename_drive.txt' | $tee_cmd > " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.' . $filename_drive . '.sh');
                    }
                }
                #####################################
                # BACKUP LVM LAYOUT
                #####################################
                $check_lvm_installed = $this->Cmd->exe("'which pvscan 2>&1 >/dev/null && echo TRUE'", true);
                if ($check_lvm_installed == 'TRUE')
                {
		    $vgs=$this->Cmd->Exe("vgs -o name --noheadings", true);
		    $vgs=explode("\n", $vgs);
		    foreach ( $vgs as $vg ) {
			$vg=ltrim($vg);
			$restore_type = 'logical_volumes_restore';

			$filename_vgcfgbackup = "vgcfgbackup_$vg.txt";
			$this->Cmd->exe("echo >> " . $this->Session->get('restore.script.local'));
			$this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
			$this->Cmd->exe("echo '# " . strtoupper(str_replace('_', ' ', $restore_type)) . "' >> " . $this->Session->get('restore.script.local'));
			$this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
			$content = [];
			$content [] = '# To restore, run on backup server: ';
			$content [] = 'ssh ' . $ssh_connection . ' "bash /tmp/restore/scripts/' . $restore_type . '.sh"';
			$content [] = '';
			$content [] = '--- OR ---';
			$content [] = '';
			$content [] = '# Or run manually on the rescue system:';
			$content [] = '';
			$this->Cmd->exe("echo '" . implode("\n", $content) . "' >> " . $this->Session->get('restore.script.local'));
			$output = $this->Cmd->exe("'vgcfgbackup -f /tmp/$filename_vgcfgbackup $vg'", true);
			preg_match('/Volume group "(.+)"/', $output, $matches);
			$volume_group = $matches[1];
			$this->Cmd->exe("'(cat /tmp/$filename_vgcfgbackup)' > " . $this->Session->get('meta.path') . "$filebase.$filename_vgcfgbackup", true);
			// build the restore file
			$physical_volumes = $this->Cmd->exe("grep -E -A2 'pv[0-9]+ {' " . $this->Session->get('meta.path') . "$filebase.$filename_vgcfgbackup");
			foreach (explode('--', $physical_volumes) as $physical_volume)
			{
			    preg_match('/id = \"(.+)\"/', $physical_volume, $matches);
			    $id = $matches[1];
			    preg_match('/device = \"(.+)\"/', $physical_volume, $matches);
			    $device = $matches[1];
			    $this->Cmd->exe("echo '# re-create the physical volume with pvcreate \npvcreate -ff --uuid \"$id\" --restorefile /tmp/meta/$filebase.$filename_vgcfgbackup $device' | $tee_cmd >> " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.sh');
			}
			//volume restore
			$this->Cmd->exe("echo '# restore the volume group with vgcfgrestore \nvgcfgrestore -f /tmp/meta/$filebase.$filename_vgcfgbackup $volume_group' | $tee_cmd >> " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.sh');
			// activate volumes
			$this->Cmd->exe("echo '# activate all logical volumes. Check if installed correctly by typing pvs. \nvgchange -a y $volume_group' | $tee_cmd >> " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.sh');
		    }
                }

                #####################################
                # FILESYSTEMS RESTORE
                #####################################
                //restore
                $restore_type = 'filesystems_restore';
                $this->Cmd->exe("echo >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '# " . strtoupper(str_replace('_', ' ', $restore_type)) . "' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $content = [];
                $content [] = '# To restore, run on backup server: ';
                $content [] = 'ssh ' . $ssh_connection . ' "bash /tmp/restore/scripts/' . $restore_type . '.sh"';
                $content [] = '';
                $content [] = '--- OR ---';
                $content [] = '';
                $content [] = '# Or run manually on the rescue system:';
                $content [] = '';
                $this->Cmd->exe("echo '" . implode("\n", $content) . "' >> " . $this->Session->get('restore.script.local'));
                // iterate mounted devices - except docker
                $devices = $this->Cmd->exe("grep '^/dev /proc/mounts | grep -v var/lib/docker'", true);
                $patterns = [];
                $patterns [] = 'mapper';
                $patterns [] = 'sd';
                $patterns [] = 'dm';
                // store mounts
                $mounts = [];
                if (!empty($devices))
                {
                    foreach (explode("\n", $devices) as $device_line)
                    {
                        // get type and device
                        $pieces = explode(' ', $device_line);
                        $type = $pieces[2];
                        $device = $pieces[0];

                        // get uuid
                        $output = $this->Cmd->exe("blkid $device", true);
                        preg_match('/UUID=\"([^\" ]+)\"/', $output, $matches);
                        $uuid = $matches[1];

                        $this->Cmd->exe("echo 'mkfs -U $uuid --type $type $device' | $tee_cmd >> " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.sh');
                        //store mounts
                        $mounts [$pieces[0]] = $pieces[1];
                    }
                }
                #####################################
                # MOUNTS
                #####################################
                //restore
                $restore_type = 'mounts';
                $this->Cmd->exe("echo >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '# " . strtoupper(str_replace('_', ' ', $restore_type)) . "' >> " . $this->Session->get('restore.script.local'));
                $this->Cmd->exe("echo '################################################' >> " . $this->Session->get('restore.script.local'));
                $content = [];
                $content [] = '# To restore, run on backup server:';
                $content [] = 'ssh ' . $ssh_connection . ' "bash /tmp/restore/scripts/' . $restore_type . '.sh"';
                $content [] = '';
                $content [] = '--- OR ---';
                $content [] = '';
                $content [] = '# Or run manually on the rescue system...';
                $content [] = '';
                $out[] = '# Suggested mount points. Your mileage  may vary.';
                $this->Cmd->exe("echo '" . implode("\n", $content) . "' >> " . $this->Session->get('restore.script.local'));
                $mounts_ordered = [];
                foreach ($mounts as $dev => $mount)
                {
                    $m = explode('/', $mount);
                    $mounts_ordered[count($m)] [] = [$dev, $mount];
                }
                // generate mount commands
                foreach ($mounts_ordered as $index => $array)
                {
                    foreach ($array as $a)
                    {
                        $mount = $a[1];
                        $dev = $a[0];
                        $this->Cmd->exe("echo 'mkdir /mnt/poppins" . $mount . "; mount " . $dev . "  /mnt/poppins" . $mount . "' | $tee_cmd >> " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.sh');
                    }
                }
                //chroot
                $content = [];
                $content [] = '';
                $content [] = '# You may want to restore the bootloader later by chrooting and installing grub by running for example: \'grub-install /dev/sda\'; update-grub';
                $content [] = '';
                $content [] = '# for i in dev dev/pts sys proc run; do mount --bind /$i /mnt/poppins/$i; done;';
                $content [] = '# chroot /mnt/poppins /bin/bash';
                $this->Cmd->exe("echo '" . implode("\n", $content) . "' | $tee_cmd >> " . $this->Session->get('scripts.path') . $filebase . '.' . $restore_type . '.sh');
            }
            else
            {
                $this->App->warn('Cannot create restore scripts. Must be root!');
            }
        }
    }

    /**
     * Prepare backups
     */
    function prepare()
    {
        #####################################
        # CREATE SYNC DIR
        #####################################
        if (!file_exists($this->rsyncdir))
        {
            $this->App->out("Create sync dir $this->rsyncdir...");
            $this->create_syncdir();
        }
        #####################################
        # CREATE OTHER DIRS
        #####################################
        $dirs = ['meta', 'files', 'restore', 'restore/scripts'];
        if ($this->Config->get('mysql.enabled'))
        {
            $dirs [] = 'mysql';
        }
        // create directories
        foreach ($dirs as $dir)
        {
            if (!file_exists($this->rsyncdir . '/' . $dir))
            {
                $this->App->out("Create $dir dir $this->rsyncdir/$dir...");
                $this->Cmd->exe("mkdir -p $this->rsyncdir/$dir");
            }
        }
        #####################################
        # EMPTY DIRS
        #####################################
        if (!$this->Options->is_set('n'))
        {
            $dirs = ['meta', 'restore', 'restore/scripts'];
            // empty dirs
            foreach ($dirs as $dir)
            {
                $this->App->out("Empty meta directory " . $this->rsyncdir . '/' . $dir . "...");
                // take precautions when executing an rm command!
                foreach([$this->rsyncdir, $dir] as $variable)
                {
                    $variable = trim($variable);
                    if (!$variable || empty($variable) || $variable == '' || preg_match('/^\/+$/', $variable))
                    {
                        $this->App->fail('Cannot execute a rm command as a variable is empty!');
                    }
                }
                $this->Cmd->exe("rm -f " . $this->rsyncdir . '/' . $dir . "/* 2>/dev/null");
            }
        }
        #####################################
        # PRE BACKUP JOB
        #####################################
        $this->jobs('pre');
    }

    /**
     * Rsync remote files and directories
     */
    function rsync()
    {
        //rsync backups
        $this->App->out('Sync data', 'header');
        // dry run?
        if($this->Options->is_set('n'))
        {
            $this->App->out('DRY RUN!');
            $this->App->out();
        }
        #####################################
        # CHECK FOR MOUNTED FILESYSTEMS
        #####################################
        $this->App->out('Check mounted remote filesystems...');
        if (!$this->Config->get('rsync.cross-filesystem-boundaries'))
        {
            $mounts = [];
            $output = $this->Cmd->exe("'cat /proc/mounts'", true);
            $output = explode("\n", $output);
            foreach($output as $o)
            {
                if (preg_match('/^\//', $o))
                {
                    $p = explode(' ', $o);
                    $mounts []= $p[1];
                }
            }
            $this->App->out(implode(", ", $mounts), 'simple-indent');
            $excluded = $this->Config->get('excluded');
            $excluded_paths = [];
            foreach ($excluded as $k => $v)
            {
                $exploded = explode(',', $v);
                foreach($exploded as $e)
                {
                    $excluded_paths[$k][]=  rtrim($k, '/').'/'.rtrim($e, '/');
                }
            }
            $included = array_keys($this->Config->get('included'));
            // check if mounts are in backup paths
            foreach($mounts as $m)
            {
                # initiate crossed_path
                $crossed_path = false;
                # the mount is not specified in included
                if(!in_array($m, $included))
                {
                    foreach ($included as $i)
                    {
                        # check if mount is found in included dirs
                        if (0 === strpos($m, $i))
                        {
                            # check if mount is excluded
                            if (!array_key_exists ($i, $excluded_paths))
                            {
                                $crossed_path = $i;
                            }
                            else
                            {
                                # check all excluded paths
                                foreach($excluded_paths[$i] as $p)
                                {
                                    # compare the paths with mounts
                                    if(0 === strpos($m, $p))
                                    {
                                        $crossed_path = false;
                                        break;
                                    }
                                    else
                                    {
                                        $crossed_path = $i;
                                    }
                                }
                            }
                        }
                    }
                    # crossed filesystem found
                    if($crossed_path)
                    {
                        $this->App->warn('Mount point "'.$m.'" found in path "'.$crossed_path.'". Will not cross filesystem boundaries!');
                    }
                }
            }
        }
        $this->App->out();
        #####################################
        # RSYNC OPTIONS
        #####################################
        $this->App->out('Run rsync commands...');
        $this->App->out();
        //options
        $o = [];
        $o [] = "--delete-excluded --delete --numeric-ids";

        //ssh
        if ($this->Config->get('remote.ssh'))
        {
            $ssh = $this->Cmd->parse('{SSH}');
            $sshopts = $this->Session->Get('ssh.options');
            $o [] = '-e "' . $ssh . ' ' . $sshopts.'"';
        }

        // general options
        if($this->Options->is_set('n'))
        {
            $o [] = "--dry-run";
        }
        if ($this->Config->get('rsync.verbose'))
        {
            $o [] = "-v";
        }
        if (!$this->Config->get('rsync.cross-filesystem-boundaries'))
        {
            $o [] = "-x";
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
        //add a timestamp to every file
        if($this->Config->get('rsync.timestamps'))
        {
            $o [] = '--log-format="%t %n %L"';
        }
        // add default options
        $o []= '-as';
        $rsync_options = implode(' ', $o);
        #####################################
        # RSYNC THE DIRECTORIES
        #####################################
        //restore
        $restore_type = 'data_restore';
        $this->Cmd->exe("echo >> ".$this->Session->get('restore.script.local'));
        $this->Cmd->exe("echo '################################################' >> ".$this->Session->get('restore.script.local'));
        $this->Cmd->exe("echo '# ".strtoupper(str_replace('_', ' ',$restore_type))."' >> ".$this->Session->get('restore.script.local'));
        $this->Cmd->exe("echo '################################################' >> ".$this->Session->get('restore.script.local'));
        $filebase = $this->Session->get('meta.filebase');
        $content = [];
        $content []= '# To restore, run on backup server:';
        $content []= $this->Session->get('scripts.path').$restore_type.'.sh';
        $content []= '';
        $content []= '--- OR ---';
        $content []= '';
        $content []= '# Or run manually on the backup server...';
        $content []= '';
        $this->Cmd->exe("echo '".implode("\n",$content)."' >> ".$this->Session->get('restore.script.local'));
        // record if a process has failed
        $failed_rsync_process = false;
        // mark time
        foreach ($this->Config->get('included') as $source => $target)
        {
            // we need to encode this string because the path may contain dots
            $this->Session->set(['chrono', 'rsync "'.$source .'"', 'start'], date('U'));
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
            $ssh_connection = ($this->Config->get('remote.ssh'))? $this->Config->get('remote.user') . "@" . $this->Config->get('remote.host'):'';
            $rsync_seperator = ($this->Config->get('remote.ssh'))? ':':'';
            // the rsync command
            $cmd = "rsync $rsync_options $excluded " .$ssh_connection. $rsync_seperator. "\"$sourcedir\" '$targetdir' 2>&1";
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
            //set to false
            $this->rsync_success = false;
            while ($i <= $attempts)
            {
                $output = $this->Cmd->exe("$cmd");
                $this->App->out($output);
                //WARNINGS - allow some rsync errors to occur
                if (in_array($this->Cmd->exit_status, [24]))
                {
                    $message = $this->get_rsync_status($this->Cmd->exit_status);
                    $message = (empty($message)) ? '' : ': "' . $message . '".';
                    $this->App->notice("Rsync of $sourcedir directory exited with a non-zero status! Non fatal, will continue. Exit status: " . $this->Cmd->exit_status . $message);
                    $this->rsync_success = true;
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
                    $this->rsync_success = true;
                    break;
                }
            }
            //check if successful
            if (!$this->rsync_success)
            {
                $message = $this->get_rsync_status($this->Cmd->exit_status);
                $message = (empty($message)) ? '' : ': "' . $message . '".';
                $output = "Rsync of $sourcedir directory failed! Aborting! Exit status " . $this->Cmd->exit_status . $message;
                $this->App->warn($output);
                // record failed process
                $failed_rsync_process = true;
            }
            else
            {
                $this->App->out("OK!", 'simple-success');
                $this->Session->set(['chrono', 'rsync "'.$source .'"', 'stop'], date('U'));
                #####################################
                # RSYNC RESTORE
                #####################################
                $tee_cmd = "tee -a ".$this->Session->get('restore.script.local');
                $rsync_options2 = preg_replace('/\s+/', ' ', preg_replace('/-e \".+\"/', '-e ssh', $rsync_options));
                $this->Cmd->exe("echo rsync ".$rsync_options2." \'$targetdir\' " .$ssh_connection. $rsync_seperator. "\'/mnt/poppins$sourcedir\' | $tee_cmd  >> ".$this->Session->get('scripts.path').$filebase.'.'.$restore_type.'.sh');
            }
        }
        // abort if one rsync process has failed
        if($failed_rsync_process)
        {
            $this->App->fail('An rsync process has failed!');
        }
    }

    /**
     * Post backup script depending on successful rsync run
     */
    function wrap_up()
    {
        //check fatal error
        if(!$this->rsync_success)
        {
            // even if the backup job failed, execute the post-script!
            if($this->Config->get('remote.backup-onfail') == 'abort')
            {
                // do not run post-backup script
                $this->App->warn("Will not run post-backup script!");
            }
            else
            {
                $this->App->out("OK!");
                // run post-backup scripts
                $this->jobs('post');
            }
            $this->App->fail('One or more rsync jobs have failed!', 'simple-error');
        }
        else
        {
            // run post-backup scripts
            $this->jobs('post');
        }
    }

}

