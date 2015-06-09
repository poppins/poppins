<?php

class Backup
{
    public $App;
    public $Cmd;
      
    public $settings;
    
            
    function __construct($App)
    {
        $this->App = $App;
        $this->Cmd =  $this->App->Cmd;
        
        $this->settings = $this->App->settings;
        
        $this->snapshotdir = $App->settings['local']['snapshotdir'];
        
    }
    
    function init()
    {
        //validate (check if LOCK file exists)
        $this->validate();
        $this->App->quit();
        //pre backup
        $this->prebackup();
        //prepare 
        $this->prepare();
        //gather remote system info
        $this->probe();
        //mysql
        $this->mysql();
        //rsync
        $this->rsync();
    }
    
    function mysql()
    {
        #####################################
        # MYSQL BACKUPS
        #####################################
        if ($_settings['mysql']['enabled'])
        {
            $MYSQL_REMOTE_USER = ($_settings['mysql']['remote.user']) ? $_settings['mysql']['remote.user'] : 'root';

            # BTRFS: rsync.tmp is OK, we will snapshot that
            //TODO localdir removed, ok?
            $MYSQLDUMPDIR = ($_settings['local']['filesystem'] == 'ZFS') ? "$SNAPDIR/mysqldumps" : "$SNAPDIR/rsync.tmp/mysqldumps";

            $Cmd->exe($_settings['cmd']['rm'] . " -rf $MYSQLDUMPDIR");
            $Cmd->exe("mkdir -p $MYSQLDUMPDIR");

            $configfiles = trim(shell_exe("ssh $MYSQL_REMOTE_USER@$H 'ls .my.cnf*'"));
            $dbfound = false;
            $mysqlerror = false;
            foreach (explode("\n", $configfiles) as $configfile)
            {
                #$configfile = preg_replace('/^.+\//', '', $instance);
                $instance = preg_replace('/^.+my\.cnf(\.)?/', '', $configfile);
                if (!trim($instance))
                    continue;
                $Cmd->exe("echo instance:  $instance | tee -a $LOGFILE");
                $Cmd->exe("mkdir $MYSQLDUMPDIR/mysql$instance");
                $dbs = trim(shell_exe("ssh -C $MYSQL_REMOTE_USER@$H 'echo show databases | mysql --defaults-file=$configfile'"));
                foreach (explode("\n", $dbs) as $db)
                {
                    if (empty($db))
                    {
                        continue;
                    }
                    elseif ($db == "information_schema")
                    {
                        $Cmd->exe("echo not backing up $db | tee -a $LOGFILE");
                        continue;
                    }
                    else
                    {
                        $dbfound = true;
                    }
                    $Cmd->exe("echo -n $db... ");
                    $Cmd->exe("ssh  $MYSQL_REMOTE_USER@$H mysqldump --defaults-file=$configfile --ignore-table=mysql.event --routines --single-transaction --quick --databases $db | gzip > $MYSQLDUMPDIR/mysql$instance/$db.sql.gz", $mysqlerror);
                    if ($mysqlerror)
                    {
                        break;
                    }
                    else
                    {
                        $Cmd->exe("echo mysql instance backed up. | tee -a $LOGFILE");
                    }
                }
            }
            //valid db is found
            if ($dbfound)
            {
                if (!$mysqlerror)
                {
                    $Cmd->exe("echo -n dumped mysql databases -  | tee -a $LOGFILE");
                    $Cmd->exe("date | tee -a $LOGFILE");
                }
                else
                {
                    $Cmd->exe("echo -n mysql databases failed!  | tee -a $LOGFILE");
                    $Cmd->exe($_settings['cmd']['rm'] . " --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
                    $Cmd->exe("date | tee -a $LOGFILE");
                    die();
                }
            }
            else
            {
                $Cmd->exe("echo -n no databases found!  | tee -a $LOGFILE");
            }
        }
    }

    function prebackup()
    {
        #####################################
        # PRE ROTATE JOB
        #####################################
        # do our thing on the remote end. Best to put this in a separate script.
        # you can dump databases, take file system snapshots etc. 
        //check if jobs
        if ($this->settings['actions']['pre_backup_remote_job'])
        {
            $this->App->out('Found remote job, executing... (' . date('Y-m-d.H-i-s') . ')');
            $success = $this->Cmd->exe("ssh $U@$H " . $_settings['actions']['pre_backup_remote_job'], 'passthru');
            if ($success)
            {
                $this->App->out('OK! Job done... (' . date('Y-m-d.H-i-s') . ')');
            }
            else
            {
                $this->App->fail("Cannot execute remote job: '" . $_settings['actions']['pre_backup_remote_job'] . "'");
            }
        }
        else
        {
            $this->App->out('No remote jobs found...');
        }
    }

    function probe()
    {
        $App->out('Gather information about disk layout...');
        # remote disk layout and packages
        if ($_settings['remote']['os'] == "Linux")
        {
            $Cmd->exe("ssh $U@$H '( df -hT ; vgs ; pvs ; lvs ; blkid ; lsblk -fi ; for disk in $(ls /dev/sd[a-z]) ; do fdisk -l \$disk; done )' > $SNAPDIR/incremental/" . $_settings['remote']['host'] . '.' . date('Y-m-d_H.i.s', $App->start_time) . ".poppins.disk-layout.txt 2>&1");
        }
        $App->out('Gather information about packages...');
        switch ($_settings['remote']['distro'])
        {
            case 'Debian':
            case 'Ubuntu':
                $success = $Cmd->exe("ssh $U@$H \"aptitude search '~i !~M' -F '%p' --disable-columns | sort -u\" > $SNAPDIR/incremental/" . $_settings['remote']['host'] . '.' . date('Y-m-d_H.i.s', $App->start_time) . ".poppins.packages.txt", 'passthru');
                if ($success)
                {
                    $App->out('OK');
                }
                else
                {
                    $App->fail('Failed to retrieve package list!');
                }
                break;
            default:
                break;
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
        foreach ($_settings['directories'] as $sourcedir => $targetdir)
        {
            $dirs = explode(',', str_replace(' ', '', $_settings['exclude'][$sourcedir]));

            $EXCLUDE = '';
            foreach ($dirs as $dir)
            {
                $EXCLUDE .= " --exclude=$dir";
            }

            print "rsync $targetdir...";
            # the difference: on a plain old classic file system we use snapshot
            # directories and hardlinks;
            switch ($_settings['local']['filesystem'])
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
        if (file_exists( $this->snapshotdir . "/LOCK"))
        {
            $this->App->fail("LOCK file exists!", 'LOCKED');
        }
        else
        {
            $this->App->out('Create LOCK file...');
            $this->Cmd->exe("touch " .  $this->snapshotdir . "/LOCK", 'passthru');
        }
    }

}

class BTRFSBackup extends Backup
{

    function prepare()
    {
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
                $Cmd->exe($_settings['cmd']['rm'] . " --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
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
    
}

class ZFSBackup extends Backup
{
}
