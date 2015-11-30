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
        //check config directories 
        $dirs = [];
        if(!empty($this->settings['mysql']['configdirs']))
        {
             $dirs = explode(',', $this->settings['mysql']['configdirs']);
        }
        //assume home dir
        else
        {
            $dirs []= '~';
        }
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
                $this->App->warn('Cannot access remote dir ' . $dir.'...');
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
                $this->App->warn('Cannot find mysql config files in remote dir ' . $dir.'...');
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
                        $this->App->warn("Found duplicate mysql config file $dir/$configfile...");
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
                        $this->App->Cmd->exe("$this->ssh mysqldump --defaults-file=$dir/$configfile --ignore-table=mysql.event --routines --single-transaction --quick --databases $db | gzip > $instancedir/$db.sql.gz");
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
        # PRE BACKUP JOBS
        #####################################
        # do our thing on the remote end. 
        $this->App->out('PRE BACKUP JOB', 'header');
        //check if jobs
        if (!empty($this->settings['remote']['pre-backup-script']))
        {
            $this->App->out('Remote script configured, validating...');
            $script = $this->settings['remote']['pre-backup-script'];
            //test if the script exists
            $this->App->Cmd->exe($this->ssh ." 'test -x ".$script."'");
            if($this->App->Cmd->is_error())
            {
                $message = 'Remote script is not an executable script!';
                if($this->settings['remote']['pre-backup-onfail'] == 'abort')
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
            $output = $this->App->Cmd->exe($this->ssh ." '".$script."'");
            if($this->App->Cmd->is_error())
            {
                $message = 'Remote script did not run successfully!';
                if($this->settings['remote']['pre-backup-onfail'] == 'abort')
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
                $this->App->out('Output:');
                $this->App->out("\n" . $output . "\n");
            }
        }
        else
        {
            $this->App->out('No pre backup script defined...');
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
                        //warning not desired
                        //$this->App->warn('Failed to retrieve package list with yumdb! Is it installed on the remote machine?');
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
                case 'Arch':
                case 'Manjaro':
                    $this->App->Cmd->exe("$this->ssh \"pacman -Qet\" > $this->rsyncdir/meta/" . $filebase . ".packages.txt");
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
            //excluded files
            $excluded = implode(' ', $excluded);
            //output command
            $this->App->out("rsync '$source' @ ".date('Y-m-d H:i:s')."...", 'indent');
            if(!is_dir("$this->rsyncdir/files/$target"))
            {
                $this->App->out("Create target dir $this->rsyncdir/files/$target...");
                $this->App->Cmd->exe("mkdir -p $this->rsyncdir/files/$target");
            }
            $cmd = "rsync $rsync_options -xa $excluded " . $this->settings['remote']['user'] . "@" . $this->settings['remote']['host'] . ":\"$sourcedir\" \"$targetdir\"";
            $this->App->out($cmd);
            //obviously try rsync at least once :)
            $attempts = 1;
            //retry attempts on rsync fail
            if(isset($this->settings['rsync']['retry-count']))
            {
                $attempts += (integer)$this->settings['rsync']['retry-count'];
            }
            //retry timeout between attempts
            $timeout = 0;
            if(isset($this->settings['rsync']['retry-timeout']))
            {
                $timeout += (integer)$this->settings['rsync']['retry-timeout'];
            }
            $i = 1;
            $success = false;
            while ($i <= $attempts)
            {
                $output = $this->App->Cmd->exe("$cmd");
                $this->App->out($output);
                //allow 24
                if ($this->App->Cmd->exit_status == 24)
                {
                    $this->App->warn('Rsync of '.$sourcedir.' directory returned exit code 24...');
                    $success = true;
                    break;
                }
                elseif ($this->App->Cmd->exit_status != 0)
                {
                    $this->App->warn("Rsync of $sourcedir directory attempt $i/$attempts failed! Exit code ".$this->App->Cmd->exit_status.'...');
                    $message = [];
                    if($i != $attempts)
                    {
                        $message [] = "Will retry rsync attempt " . ($i + 1) . " of $attempts in $timeout second(s)...\n";
                        sleep($timeout);
                    }
                    $this->App->out(implode(' ', $message));
                    $i++;
                }
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
                $this->App->fail("Rsync $sourcedir failed! Exit status: ".$this->App->Cmd->exit_status.'.');
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
