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
        
        $this->syncdir = $this->settings['local']['hostdir'].'/'.$this->syncdir;
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
        $this->App->quit();
        //rsync
        $this->rsync();
    }
    
    function mysql()
    {
        #####################################
        # MYSQL BACKUPS
        #####################################
        $this->App->out('MySQL Backups', 'header');
        //check users
        $dirs = explode(',', $this->settings['mysql']['configdirs']);
        //cache config files
        $cached = [];
        //iterate dirs    
        foreach ($dirs as $dir)
        {
            $configfiles = $this->App->Cmd->exe("$this->ssh 'cd $dir;ls .my.cnf*'");
            if ($configfiles)
            {
                $configfiles = explode("\n", $configfiles);
            }
            else
            {
                $configfiles = [];
                $this->App->out('WARNING! No mysql config files found in remote dir ' . $dir.'!', 'warning');
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
                    $this->App->out("WARNING! Found duplicate mysql config file $dir/$configfile!", 'warning');
                    continue;
                }
                else
                {
                    $cached []= $contents;
                }
                
                $this->App->out("Backup databases from $dir/$configfile");
                $instancedir = "$this->syncdir/mysql/$instance";
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
            $this->App->out('Found remote job, executing... (' . date('Y-m-d.H-i-s') . ')');
            $output = $this->App->Cmd->exe($this->ssh ." '".$this->settings['actions']['pre_backup_remote_job']."'", 'exec');
            if ($output)
            {
                $this->App->out('OK! Job done... (' . date('Y-m-d.H-i-s') . ')');
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
            $this->App->Cmd->exe("$this->ssh '( df -hT ; vgs ; pvs ; lvs ; blkid ; lsblk -fi ; for disk in $(ls /dev/sd[a-z]) ; do fdisk -l \$disk; done )' > $this->syncdir/meta/" . $filebase . ".disk-layout.txt 2>&1");
        }
        $this->App->out('Gather information about packages...');
        switch ($this->App->settings['remote']['distro'])
        {
            case 'Debian':
            case 'Ubuntu':
                $success = $this->App->Cmd->exe("$this->ssh \"aptitude search '~i !~M' -F '%p' --disable-columns | sort -u\" > $this->syncdir/meta/" . $filebase . ".packages.txt", 'passthru');
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
        if (!file_exists($this->syncdir))
        {
            $this->App->out("Create sync dir $this->syncdir...");
            $this->App->Cmd->exe("mkdir " .  $this->syncdir, 'passthru');
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
            if (!file_exists($this->syncdir.'/'.$aa))
            {
                $this->App->out("Create $aa dir $this->syncdir/$aa...");
                $this->App->Cmd->exe("mkdir $this->syncdir/$aa", 'passthru');
            }
        }
    }
    
    function rsync()
    {
        #####################################
        # RSYNC OPTIONS
        #####################################
        $App->out('Validate rsync options...');
        $o = [];
        $o [] = "--numeric-ids";

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
        $RSYNC_OPTIONS = implode(' ', $o);
        #####################################
        # RSYNC DIRECTORIES
        #####################################
        foreach ($this->settings['directories'] as $sourcedir => $targetdir)
        {
            $dirs = explode(',', str_replace(' ', '', $this->settings['exclude'][$sourcedir]));

            $EXCLUDE = '';
            foreach ($dirs as $dir)
            {
                $EXCLUDE .= " --exclude=$dir";
            }

            print "rsync $targetdir...";
            # the difference: on a plain old classic file system we use snapshot
            # directories and hardlinks;
            switch ($this->settings['local']['filesystem'])
            {
                case 'ZFS':
                    $Cmd->exe("mkdir -p $SNAPDIR/$targetdir");
                    $Cmd->exe("rsync --delete-excluded --delete $RSYNC_OPTIONS -xae ssh $EXCLUDE $U@$H:$sourcedir/ $SNAPDIR/$targetdir/ | tee -a $LOGFILE", $error);
                    break;
                case 'BTRFS':
                    $Cmd->exe("rsync --delete-excluded --delete $RSYNC_OPTIONS -xae ssh $EXCLUDE $U@$H:$sourcedir/ $SNAPDIR/rsync.tmp/$targetdir/", $error);
                    break;
                default:
                    $Cmd->exe("mkdir -p $SNAPDIR/rsync.tmp/$targetdir");
                    $Cmd->exe("rsync --delete-excluded --delete $RSYNC_OPTIONS -xae ssh $EXCLUDE --link-dest=$SNAPDIR/daily.0/${localdir}/ $U@$H:$sourcedir/ $SNAPDIR/rsync.tmp/$targetdir/", $error);
            }
            # we willen weten of alle rsyncs succesvol beëindigd zijn (source file 
            # vanished willen we nog tolereren, kan gebeuren) en anders doen we geen rotate. 
            $Cmd->exe("echo $targetdir - exit status: $error | tee -a $LOGFILE");
            if ($error && $error != 24)
            {
                echo 'FAILED';
                $failed = 1;
            }
        }
    }

    function validate()
    {
        #####################################
        # CREATE LOCK FILE
        #####################################
        # check for lock
        if (file_exists( $this->settings['local']['hostdir'] . "/LOCK"))
        {
            $this->App->fail("LOCK file exists!", 'LOCKED');
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
    protected $syncdir = 'rsync.btrfs.subvol';

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
    protected $syncdir = 'rsync.dir';
}

class ZFSBackup extends Backup
{
    protected $syncdir = 'rsync.zfs.subvol';
}
