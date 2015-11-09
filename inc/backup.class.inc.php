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
        
        $this->rsyncdir = $this->settings['local']['rsyncdir'];
    }
    
    function init()
    {
        //validate (check if LOCK file exists)
        $this->validate();
        //pre backup
        ////$this->jobs();
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
            $output = false;
            //check if allowed
            $this->App->Cmd->exe("$this->ssh 'cd $dir';");
            if($this->App->Cmd->is_error())
            {
                $this->App->out('WARNING! Cannot access remote dir ' . $dir.'...', 'warning');
            }
            else
            {
                $output = $this->App->Cmd->exe("$this->ssh 'cd $dir;ls .my.cnf* 2>/dev/null'");
            }
            //check output
            if ($output)
            {
                $configfiles = explode("\n", $output);
            }
            else
            {
                $configfiles = [];
                $this->App->out('WARNING! No mysql config files found in remote dir ' . $dir.'...', 'warning');
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
                    $contents = $this->App->Cmd->exe("$this->ssh 'cd $dir;cat .my.cnf*'");
                    if (in_array($contents, $cached))
                    {
                        $this->App->out("WARNING! Found duplicate mysql config file $dir/$configfile...", 'warning');
                        continue;
                    }
                    else
                    {
                        $cached [] = $contents;
                    }

                    $this->App->out("Backup databases from $dir/$configfile");
                    $instancedir = "$this->rsyncdir/mysql/$instance";
                    if (!is_dir($instancedir))
                    {
                        $this->App->out("Create directory $instancedir...");
                        $this->App->Cmd->exe("mkdir -p $instancedir");
                    }
                    //get all dbs
                    $dbs = $this->App->Cmd->exe("$this->ssh 'mysql --defaults-file=\"$dir/$configfile\" --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'");
                    foreach (explode("\n", $dbs) as $db)
                    {
                        if (empty($db))
                        {
                            continue;
                        }
                        $this->App->Cmd->exe("$this->ssh mysqldump --defaults-file=$configfile --ignore-table=mysql.event --routines --single-transaction --quick --databases $db | gzip > $instancedir/$db.sql.gz");
                        if (!$this->App->Cmd->is_error())
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

    function jobs()
    {
        #####################################
        # PRE BACKUP JOB
        #####################################
        # do our thing on the remote end. Best to put this in a separate script.
        $this->App->out('PRE BACKUP REMOTE JOB', 'header');
        //check if jobs
        if (isset($this->settings['actions']) && $this->settings['actions']['pre_backup_remote_job'])
        {
            $this->App->out('Found remote job, executing... (' . date('Y-m-d H:i:s') . ')');
            $output = $this->App->Cmd->exe($this->ssh ." '".$this->settings['actions']['pre_backup_remote_job']."'");
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
        $filebase = strtolower($this->settings['local']['hostdir-name'] . '.'.$this->App->settings['application']['name']);
        $this->App->out('Remote meta data', 'header');
        //disk layout
        if ($this->settings['meta']['remote-disk-layout'])
        {
            $this->App->out('Gather information about disk layout...');
            # remote disk layout and packages
            if ($this->settings['remote']['os'] == "Linux")
            {
                $this->App->Cmd->exe("$this->ssh '( df -hT 2>&1; vgs 2>&1; pvs 2>&1; lvs 2>&1; blkid 2>&1; lsblk -fi 2>&1; for disk in $(ls /dev/sd[a-z]) ; do fdisk -l \$disk 2>&1; done )' > $this->rsyncdir/meta/" . $filebase . ".disk-layout.txt");
            }
        }
        //packages
        if ($this->settings['meta']['remote-package-list'])
        {
            $this->App->out('Gather information about packages...');
            switch ($this->App->settings['remote']['distro'])
            {
                case 'Debian':
                case 'Ubuntu':
                    $this->App->Cmd->exe("$this->ssh \"aptitude search '~i !~M' -F '%p' --disable-columns | sort -u\" > $this->rsyncdir/meta/" . $filebase . ".packages.txt");
                    $this->App->Cmd->exe("$this->ssh \"aptitude search '~i !~M' -F '%p' --disable-columns | sort -u\" > $this->rsyncdir/meta/" . $filebase . ".packages.txt");
                    if ($this->App->Cmd->is_error())
                    {
                        $this->App->fail('Failed to retrieve package list!');
                    }
                    break;
                case 'Red Hat':
                case 'CentOS':
                case 'Fedora':
                    //check if yumdb installed on remote machine
                    $_h = $this->App->settings['remote']['host'];
                    $_u = $this->App->settings['remote']['user'];
                    $this->App->Cmd->exe("ssh $_u@$_h 'yumdb --version'");
                    if ($this->App->Cmd->is_error())
                    {
                        $this->App->out('Failed to retrieve package list with yumdb! Is it installed on the remote machine?', 'warning');
                        $this->App->Cmd->exe("$this->ssh \"rpm -qa \" > $this->rsyncdir/meta/" . $filebase . ".packages.txt");
                        if ($this->App->Cmd->is_error())
                        {
                            $this->App->fail('Failed to retrieve package list!');
                        }
                    }
                    else
                    {
                        $this->App->Cmd->exe("$this->ssh \"yumdb search reason user | sort | grep -v 'reason = user' | sed '/^$/d' \" > $this->rsyncdir/meta/" . $filebase . ".packages.txt");
                        if ($this->App->Cmd->is_error())
                        {
                            $this->App->fail('Failed to retrieve package list!');
                        }
                    }
                    break;    
                default:
                    $this->App->out('Remote OS not supported.');
                    break;
            }
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
            $this->create_syncdir();
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
                $this->App->Cmd->exe("mkdir -p $this->rsyncdir/$aa");
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

        //ssh
        $ssh = $this->App->Cmd->parse('{SSH}');
        $o []= '-e "'.$ssh.' -o TCPKeepAlive=yes -o ServerAliveInterval=30"';
        
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
        // rewrite as little blocks as possible. do not set this for default!
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
            //check trailing slash
            $sourcedir = (preg_match('/\/$/', $source))? $source:"$source/";
            $targetdir = "$this->rsyncdir/files/$target/";
            
            //exclude dirs
            $excluded = [];
            if(isset($this->settings['excluded'][$source]))
            {
                $exludedirs = explode(',', $this->settings['excluded'][$source]);
                    
                foreach ($exludedirs as $d)
                {
                    $excluded []= "--exclude=$d";
                }
            }
            
            $excluded = implode(' ', $excluded);
            
            $this->App->out("rsync '$source' @ ".date('Y-m-d H:i:s')."...", 'indent');
            if(!is_dir("$this->rsyncdir/files/$target"))
            {
                $this->App->out("Create target dir $this->rsyncdir/files/$target...");
                $this->App->Cmd->exe("mkdir -p $this->rsyncdir/files/$target");
            }
            $cmd = "rsync $rsync_options -xa $excluded " . $this->settings['remote']['user'] . "@" . $this->settings['remote']['host'] . ":\"$sourcedir\" \"$targetdir\"";
            $this->App->out($cmd);
            $output = $this->App->Cmd->exe("$cmd");
            $this->App->out($output);
            if ($this->App->Cmd->exit_status == 24)
            {
                $this->App->out('WARNING! Rsync of '.$sourcedir.' returned exit code 24...', 'warning');
            }
            elseif ($this->App->Cmd->exit_status != 0)
            {
                $this->App->fail("Backup $sourcedir failed! Cannot rsync from remote server!");
            }
            else
            {
                $this->App->out("");
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
            $this->App->Cmd->exe("touch " .  $this->settings['local']['hostdir'] . "/LOCK");
        }
    }
}

class BTRFSBackup extends Backup
{
    function create_syncdir()
    {
        $this->App->Cmd->exe("btrfs subvolume create " .  $this->rsyncdir);
    }
}

class DefaultBackup extends Backup
{
    function create_syncdir()
    {
        $this->App->Cmd->exe("mkdir -p " .  $this->rsyncdir);
    }
}

class ZFSBackup extends Backup
{
    function create_syncdir()
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $this->App->Cmd->exe("zfs create " .  $rsyncdir);
    }
}
