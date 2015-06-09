#!/usr/bin/php
<?php
#####################################
# VERSION
#####################################
// In Mary Poppins, Mary, Bert, and the children ride a merry-go-round, then leave 
// the carousel on their horses to go off on a fox hunt and a horse race.
$APPNAME = 'Poppins';
// Based on rotating rsync script by frvdamme
// Rewritten and maintained by brdooms and frvdamme
$VERSION = '1.0';
###############################################################################################################
# CLASSES
###############################################################################################################
require_once('inc/lib.inc.php');
#####################################
# APPLICATION
#####################################
$App = new Application($APPNAME, $VERSION);
$App->init();
#####################################
# COMMANDS
#####################################
$Cmd = CmdFactory::create($App);
//load commands
$App->Cmd = $Cmd;
#####################################
# SETTINGS
#####################################
$Settings = new Settings($App);
$Settings->init();
//load settings
$App->settings = $Settings->get();
###############################################################################################################
# START BACKUPS
###############################################################################################################
$App->out('Initiate backups...', 'header');
#####################################
# CREATE LOCK FILE
#####################################
$_settings = $Settings->get();
# check for lock
if (file_exists($_settings['local']['snapshotdir']."/LOCK"))
{
    $App->fail("LOCK file exists!", 'LOCKED');
}
else
{
    $App->out('Create LOCK file...');
    $Cmd->exe("touch ".$_settings['local']['snapshotdir']."/LOCK", 'passthru');
}
#####################################
# SET VARIABLES
#####################################
$U = $_settings['remote']['user'];
$H = $_settings['remote']['host'];
$SNAPDIR = $_settings['local']['snapshotdir'];
#####################################
# PRE ROTATE JOB
#####################################
# do our thing on the remote end. Best to put this in a separate script.
# you can dump databases, take file system snapshots etc. 
//check if jobs
if ($_settings['actions']['pre_rotate_remote_job'])
{
    $App->out('Found remote job, executing... ('.date('Y-m-d.H-i-s').')');
    $success = $Cmd->exe("ssh $U@$H ".$_settings['actions']['pre_rotate_remote_job'], 'passthru');
    if (!$success)
    {
        $App->fail("Cannot execute remote job: '".$_settings['actions']['pre_rotate_remote_job']."'");
    }
    else
    {
         $App->out('OK! Job done... ('.date('Y-m-d.H-i-s').')');
    }
}
else
{
    $App->out('No remote jobs found...');
}
#####################################
# BACKUPS
#####################################
//initiate
$c = BackupFactory($_settings);
$c->Cmd = $Cmd;
$c->App = $App;
$c->init();

$App->quit();
#####################################
# BTRFS SNAPSHOTS
#####################################
# if we are running on btrfs, we have to make sure the rsync.tmp directory is a proper snapshot before we start 
if($_settings['filesystem']['type'] == 'BTRFS')
{
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
	    $Cmd->exe($_settings['cmd']['rm']." --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
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
#####################################
# MYSQL BACKUPS
#####################################
if ($_settings['mysql']['enabled'])
{
    $MYSQL_REMOTE_USER = ($_settings['mysql']['remote.user'])? $_settings['mysql']['remote.user']:'root';

    # BTRFS: rsync.tmp is OK, we will snapshot that
    //TODO localdir removed, ok?
    $MYSQLDUMPDIR = ($_settings['filesystem']['type'] == 'ZFS')? "$SNAPDIR/mysqldumps":"$SNAPDIR/rsync.tmp/mysqldumps";

    $Cmd->exe($_settings['cmd']['rm']." -rf $MYSQLDUMPDIR");
    $Cmd->exe("mkdir -p $MYSQLDUMPDIR");

    $configfiles = trim(shell_exe("ssh $MYSQL_REMOTE_USER@$H 'ls .my.cnf*'"));
    $dbfound = false;
    $mysqlerror = false;
    foreach(explode("\n",$configfiles) as $configfile)
    {
        #$configfile = preg_replace('/^.+\//', '', $instance);
	$instance = preg_replace('/^.+my\.cnf(\.)?/', '', $configfile);
        if(!trim($instance)) continue;
	$Cmd->exe("echo instance:  $instance | tee -a $LOGFILE");
	$Cmd->exe("mkdir $MYSQLDUMPDIR/mysql$instance");
        $dbs = trim(shell_exe("ssh -C $MYSQL_REMOTE_USER@$H 'echo show databases | mysql --defaults-file=$configfile'"));
	foreach(explode("\n",$dbs) as $db)
	{
            if(empty($db))
            {
                continue;
            }    
	    elseif ( $db == "information_schema" )
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
    if($dbfound)
    {
        if (!$mysqlerror)
        {
            $Cmd->exe("echo -n dumped mysql databases -  | tee -a $LOGFILE");
            $Cmd->exe("date | tee -a $LOGFILE");
        }
        else
        {
            $Cmd->exe("echo -n mysql databases failed!  | tee -a $LOGFILE");
            $Cmd->exe($_settings['cmd']['rm']." --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
            $Cmd->exe("date | tee -a $LOGFILE");
            die();
        }
    }
    else
    {
        $Cmd->exe("echo -n no databases found!  | tee -a $LOGFILE");
    }  
}
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
if (in_array($this->settings['filesystem']['type'], ['ZFS', 'BTRFS']))
{
    $o [] = "--inplace";
}
$RSYNC_OPTIONS = implode(' ', $o);
#####################################
# RSYNC DIRECTORIES
#####################################
foreach($_settings['directories'] as $sourcedir => $targetdir)
{           
    $dirs = explode(',', str_replace(' ', '', $_settings['exclude'][$sourcedir])); 
    
    $EXCLUDE = '';
    foreach($dirs as $dir)
    {
        $EXCLUDE .= " --exclude=$dir";
    }

    print "rsync $targetdir...";
    # the difference: on a plain old classic file system we use snapshot
    # directories and hardlinks;
    switch($_settings['filesystem']['type'])
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
#####################################
# ZFS
#####################################
#to reflect the backup time
if($_settings['filesystem']['type'] == 'ZFS')
{
    $Cmd->exe("touch $SNAPDIR"); 
}
else
{    
    if ( file_exists("$SNAPDIR/rsync.tmp" ))
    {
	# when rotating, the directories will have timestamps on them - 
	# so we can check when it's time for a weekly or monthly snapshot
	$Cmd->exe("touch $SNAPDIR/rsync.tmp  | tee -a $LOGFILE");
    }
    else
    {
	$Cmd->exe("echo not touching rsync.tmp - not a directory! | tee -a $LOGFILE");
    }
}

$Cmd->exe("echo -n rsyncing done -  | tee -a $LOGFILE");
$Cmd->exe("date | tee -a $LOGFILE");
#####################################
# ROTATE
#####################################
# only rotate the backups if the backup actually succeeded
if (!$failed)
{
    foreach(['daily', 'weekly'] as $interval)
    {
        //initiate
        $c = RotatorFactory($interval, $_settings);
        $c->init();
    }
}
else
{    
    $Cmd->exe("echo one or more of the rsync copy tasks failed - no rotation! | tee -a $LOGFILE");
}

$Cmd->exe("echo -n backup stop -  | tee -a $LOGFILE");
$Cmd->exe("date | tee -a $LOGFILE");
#####################################
# REMOVE LOCK
#####################################
#delete lock
$Cmd->exe($_settings['cmd']['rm']." --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
$Cmd->exe("exit $failed"); # 1 is failed, 0 is OK

$App->succeed();