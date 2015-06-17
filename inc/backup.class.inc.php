<?php

class Backup
{
    public $App;
      
    public $settings;
    
    private $ssh;
    
            
    function __construct($App)
    {
        $this->App = $App;
        
        $this->settings = $this->App->settings;
        
        $this->ssh = "ssh ".$this->settings['remote']['user']."@".$this->settings['remote']['host'];
        
        $this->rsyncdir = $this->settings['local']['hostdir'].'/'.$this->rsyncdir;
    }
    
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
        if ($this->settings['mysql']['enabled'])
        {
            $this->mysql();
        }
        //rsync
        $this->rsync();
    }
    
    function mysql()
    {
        #####################################
        # MYSQL BACKUPS
        #####################################
        $this->App->out('Mysql backups', 'header');
        //check users
        $dirs = explode(',', $this->settings['mysql']['configdirs']);
        //cache config files
        $cached = [];
        //iterate dirs    
        foreach ($dirs as $dir)
        {
            $configfiles = $this->App->Cmd->exe("$this->ssh 'cd $dir;ls .my.cnf* 2>/dev/null'");
            if ($configfiles)
            {
                $configfiles = explode("\n", $configfiles);
            }
            else
            {
                $configfiles = [];
                $this->App->out('WARNING! No mysql config files found in remote dir ' . $dir.'...', 'warning');
                continue;
            }
            //iterate config files
            foreach ($configfiles as $configfile)
            {
                //instance
                $instance = preg_replace('/^.+my\.cnf(\.)?/', '', $configfile);
                $instance = ($instance)? $instance:'default';
                
                //ignore if file is the same
                $contents = $this->App->Cmd->exe("$this->ssh 'cd $dir;cat .my.cnf*'");
                if(in_array($contents, $cached))
                {
                    $this->App->out("WARNING! Found duplicate mysql config file $dir/$configfile...", 'warning');
                    continue;
                }
                else
                {
                    $cached []= $contents;
                }
                
                $this->App->out("Backup databases from $dir/$configfile");
                $instancedir = "$this->rsyncdir/mysql/$instance";
                if(!is_dir($instancedir))
                {
                    $this->App->Cmd->exe("mkdir -p $instancedir", 'passthru');
                }    
                //get all dbs
                $dbs = $this->App->Cmd->exe("$this->ssh 'mysql --defaults-file=$dir/$configfile --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'");
                foreach (explode("\n", $dbs) as $db)
                {
                    if (empty($db))
                    {
                        continue;
                    }
                    $success = $this->App->Cmd->exe("$this->ssh mysqldump --defaults-file=$configfile --ignore-table=mysql.event --routines --single-transaction --quick --databases $db | gzip > $instancedir/$db.sql.gz", 'passthru');
                    if ($success)
                    {
                        $this->App->out("$db... OK.", 'indent');
                    }
                    else
                    {
                        $this->App-fail("MySQL backup failed!");
                    }
                }
            }
        }
    }

    function jobs()
    {
        #####################################
        # PRE BACKUP JOB
        #####################################
        # do our thing on the remote end. Best to put this in a separate script.
        $this->App->out('PRE BACKUP REMOTE JOB', 'header');
        //check if jobs
        if ($this->settings['actions']['pre_backup_remote_job'])
        {
            $this->App->out('Found remote job, executing... (' . date('Y-m-d H:i:s') . ')');
            $output = $this->App->Cmd->exe($this->ssh ." '".$this->settings['actions']['pre_backup_remote_job']."'", 'exec');
            if ($output)
            {
                $this->App->out('OK! Job done... (' . date('Y-m-d H:i:s') . ')');
                $this->App->out('Output:');
                $this->App->out("\n" . $output . "\n");
            }
            else
            {
                $this->App->fail("Cannot execute remote job: \"" . $this->settings['actions']['pre_backup_remote_job'] . "\"");
            }
        }
        else
        {
            $this->App->out('No remote jobs found...');
        }
    }

    function meta()
    {
        //variables
        $filebase = strtolower($this->settings['remote']['host'] . '.'.$this->App->settings['signature']['application']);
        
        $this->App->out('Remote meta data', 'header');
        $this->App->out('Gather information about disk layout...');
        # remote disk layout and packages
        if ($this->settings['remote']['os'] == "Linux")
        {
            $this->App->Cmd->exe("$this->ssh '( df -hT ; vgs ; pvs ; lvs ; blkid ; lsblk -fi ; for disk in $(ls /dev/sd[a-z]) ; do fdisk -l \$disk; done )' > $this->rsyncdir/meta/" . $filebase . ".disk-layout.txt 2>&1");
        }
        $this->App->out('Gather information about packages...');
        switch ($this->App->settings['remote']['distro'])
        {
            case 'Debian':
            case 'Ubuntu':
                $success = $this->App->Cmd->exe("$this->ssh \"aptitude search '~i !~M' -F '%p' --disable-columns | sort -u\" > $this->rsyncdir/meta/" . $filebase . ".packages.txt", 'passthru');
                if (!$success)
                {
                    $this->App->fail('Failed to retrieve package list!');
                }
                break;
            default:
                $this->App->out('Remote OS not supported.');
                break;
        }
    }
    
    function prepare()
    {
        #####################################
        # SYNC DIR
        #####################################
        if (!file_exists($this->rsyncdir))
        {
            $this->App->out("Create sync dir $this->rsyncdir...");
            $this->App->Cmd->exe("mkdir " .  $this->rsyncdir, 'passthru');
        }
        #####################################
        # OTHER DIRS
        #####################################
        $a = ['meta', 'files']; 
        if ($this->settings['mysql']['enabled'])
        {
            $a []= 'mysql';
        }
        foreach($a as $aa)
        {
            if (!file_exists($this->rsyncdir.'/'.$aa))
            {
                $this->App->out("Create $aa dir $this->rsyncdir/$aa...");
                $this->App->Cmd->exe("mkdir $this->rsyncdir/$aa", 'passthru');
            }
        }
    }
    
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

        # general options
        if ($this->settings['rsync']['verbose'])
        {
            $o [] = "-v";
        }
        if ($this->settings['rsync']['hardlinks'])
        {
            $o [] = "-H";
        }
        if (in_array((integer) $this->settings['rsync']['compresslevel'], range(1, 9)))
        {
            $o [] = "-z --compress-level=" . $this->settings['rsync']['compresslevel'];
        }
        # "ZFS" means using the features of the ZFS file system, which allows to take 
        # snapshots of the file system instead of creating new trees of hardlinks.
        # In this case it is interesting to rewrite as little blocks as possible.
        if (in_array($this->settings['local']['filesystem'], ['ZFS', 'BTRFS']))
        {
            $o [] = "--inplace";
        }
        $rsync_options = implode(' ', $o);
        #####################################
        # RSYNC DIRECTORIES
        #####################################
        foreach ($this->settings['included'] as $source => $target)
        {
            $sourcedir = "$source/";
            $targetdir = "$this->rsyncdir/files/$target/";
            
            //exclude dirs
            $excluded = '';
            if(isset($this->settings['excluded'][$source]))
            {
                $exludedirs = explode(',', $this->settings['excluded'][$source]);
                    
                foreach ($exludedirs as $d)
                {
                    $excluded .= " --exclude=$d";
                }
            }
            
            $this->App->out("rsync '$source' @ ".date('Y-m-d H:i:s')."...", 'indent');
            if(!is_dir("$this->rsyncdir/files/$target"))
            {
                $this->App->out("Create target dir $this->rsyncdir/files/$target...");
                $this->App->Cmd->exe("mkdir $this->rsyncdir/files/$target", 'passthru');
            }
            $this->App->settings['rsync']['dir'] = $this->rsyncdir;
            # the difference: on a plain old classic file system we use snapshot
            # directories and hardlinks;
            //TODO switch eruit
            switch ($this->settings['local']['filesystem'])
            {
//                case 'ZFS':
//                    $Cmd->exe("mkdir -p $SNAPDIR/$target");
//                    $Cmd->exe("rsync --delete-excluded --delete $rsync_options -xae ssh $excluded $U@$H:$source/ $SNAPDIR/$target/", $error);
//                    break;
//                case 'BTRFS':
//                    $Cmd->exe("rsync --delete-excluded --delete $rsync_options -xae ssh $excluded $U@$H:$source/ $SNAPDIR/rsync.tmp/$target/", $error);
//                    break;
                default:
                    $cmd =  "rsync $rsync_options -xae ssh $excluded ".$this->settings['remote']['user']."@".$this->settings['remote']['host'].":$sourcedir $targetdir";
                    $this->App->out($cmd);
                    $output = $this->App->Cmd->exe("$cmd && echo OK");
                    $this->App->out($output);
                    if(substr($output, -2, 2) == 'OK')
                    {
                        $this->App->out("");
                    }
                    else
                    {
                        $this->App->fail("Backup $sourcedir failed! Cannot rsync from remote server!");
                    }
//                    $success = $this->App->Cmd->exe("rsync --delete-excluded --delete $rsync_options -xae ssh $excluded --link-dest=$SNAPDIR/daily.0/${localdir}/ $U@$H:$source/ $SNAPDIR/rsync.tmp/$target/", $error);
            }
        }
        $this->App->out("Done!");
    }

    function validate()
    {
        #####################################
        # CREATE LOCK FILE
        #####################################
        # check for lock
        if (file_exists( $this->settings['local']['hostdir'] . "/LOCK"))
        {
            $this->App->fail("LOCK file ".$this->settings['local']['hostdir']."/LOCK exists!", 'LOCKED');
        }
        else
        {
            $this->App->out('Create LOCK file...');
            $this->App->Cmd->exe("touch " .  $this->settings['local']['hostdir'] . "/LOCK", 'passthru');
        }
    }
}

class BTRFSBackup extends Backup
{
    protected $rsyncdir = 'rsync.btrfs.subvol';

    function validate()
    {
        parent::validate();
        #####################################
        # BTRFS SNAPSHOTS
        #####################################
        # not a btrfs subvolume? try to create it.
        if (is_btrfs_snapshot("$SNAPDIR/rsync.tmp"))
        {
            if (!is_btrfs_snapshot("$SNAPDIR/daily.0"))
            {
                # OK, no rsync.tmp but we can create a snapshot from the latest daily.  
                $Cmd->exe("btrfs subvolume snapshot $SNAPDIR/daily.0 $SNAPDIR/rsync.tmp", 'passthru');
            }
            else
            {
                $Cmd->exe("echo No decent snapshottable btrfs subvolume found! | tee -a $LOGFILE");
                $Cmd->exe($this->settings['cmd']['rm'] . " --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
                $Cmd->exe("date | tee -a $LOGFILE");
                die();
            }
        }

        # We should have a new clean snapshot now
        if (!is_btrfs_snapshot("$SNAPDIR/rsync.tmp"))
        {
            # still fails? Something else went wrong, give up.
            echo "Something went wrong preparing the rsync.tmp subvolume";
            die();
        }
    }

}

class DefaultBackup extends Backup
{
    protected $rsyncdir = 'rsync.dir';
}

class ZFSBackup extends Backup
{
    protected $rsyncdir = 'rsync.zfs.subvol';
}
