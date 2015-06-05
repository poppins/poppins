<?php
class RotatorFactory
{
    function create($interval, $settings)
    {
        // build the class
        $classname = ucfirst($interval).'Rotator';
        if (in_array($settings['filesystem']['type'], ['ZFS', 'BTRFS']))
        {
            $classname = $settings['filesystem']['type'].$classname;
        }
        return new $classname($settings);
    }
}

class Rotator
{    
    //logfile
    protected $logfile;
    
    //max snapshots
    protected $snapshots;
    //snapshot directory
    protected $snapshotdir;
    
    //settings, mainly from ini file
    protected $settings;
    
    //type: daily, weekly, monthly
    protected $type;
    
    function __construct($settings)
    {
        $this->settings = $settings;
        
        $this->logfile = $settings['logfile'];
        
        $this->snapshotdir = $settings['local']['snapshotdir'];
        
        $this->snapshots = $settings['snapshots'][$this->type];
        
        //actions
        $this->cp = $this->settings['cmd']['cp'];
        $this->mv = $this->settings['cmd']['mv'];
        $this->rm = $this->settings['cmd']['rm'];
    }
    
    function init()
    {
        print "Verifying $this->type snapshots...\n";
        if($this->verify())
        {
            print "Renaming $this->type snapshots...\n";
            $this->rename();
            print "Taking new $this->type snapshots...\n";
            $this->snapshot();
            print "Executing remote job...\n";
            $this->remote();
            print "Cleaning up $this->type snapshots...\n";
            $this->cleanup();
        }
        else
        {
            print "Could not pass verification. Aborting..\n";
        }
    }
    
    function remote()
    {
        $job = $this->settings['actions']["post_rotate_remote_job.$this->type"];
        if($job)
        {
            passthru("echo running remote job after daily snapshot...  | tee -a $this->logfile");
            passthru("ssh $this->settings['remote']['user']@$this->settings['remote']['host'] '$job' | tee -a $this->logfile");
            passthru("echo ...done. | tee -a $this->logfile");
        }
    }
}

#####################################
# DAILY
#####################################	
class IncrementalRotator extends Rotator
{
    protected $type = 'daily';
    
    function cleanup()
    {
        # - recycle dailies or not
        # - move the other ones instead of deleting them 
        if ($this->settings['actions']['recycle.dailies']) 
        {
            # move oldest daily snapshot to temporary directory
            if (file_exists("$this->snapshotdir/rsync.tmp"))
            {
                print "not recycling: rsync.tmp already exists\n";
                return;
            }
                
            //TODO Waarom soms een echo en anders een tee naar logfile?
            print "Recycling oldest daily snapshot: moving oldest snapshot back to rsync.tmp\n";
            passthru("$this->mv -v $this->snapshotdir/daily.$MAXDAILY $this->snapshotdir/rsync.tmp");
            
            #foreach (( i = 0 ; i < ${#LOCAL_DIRECTORIES[@]} ; i++ ))
            
            foreach (array_values($this->settings['directories']) as $targetdir)
            {
                $cmd = "touch $this->snapshotdir/rsync.tmp/$targetdir/KOPIE_VAN_OUDE_DAILY.$this->snapshots"; 
                echo "$cmd\n";
                passthru($cmd);
            }
            print "snapshot recycled\n";
        }
        else
        {
            # delete or move the snapshot - depending on MOVEDIR
            if ($this->settings['environment']['movedir'])
            {    
                    print "deleting $this->type snapshot...\n";
                    passthru("$this->rm -Rf $this->snapshotdir/$this->type.$this->snapshots");
                    print "done\n";
            }
            else
            {    
                    print "echo moving $this->type snapshot away...\n";
                    $timestamp = date('Y-m-d_H-i');
                    passthru("$this->mv -v $this->snapshotdir/$this->type.$this->snapshots $this->settings['environment']['movedir']/$this->settings['remote']['host']/$this->type.$this->snapshots.$timestamp");
                    print "done\n";
            }
        }
    }  
    
    function rename()
    {
        for($i=($this->snapshots-1); $i>=0; $i--)
        {
            $ii = $i+1;
            if (file_exists("$this->snapshotdir/$this->type.$i")) 
	    {		
                passthru("$this->mv $this->snapshotsdir/$this->type.$i $this->snapshotdir/$this->type.$ii  | tee -a $this->logfile");
            }
        }
    }
    
    function snapshot()
    {
          if (!file_exists("$this->snapshotdir/$this->type.0"))
          {
            passthru("$this->mv -v $this->snapshotsdir/rsync.tmp $this->snapshotdir/daily.0  | tee -a $this->logfile");
          }
          else
          {
              passthru("echo $this->type.0 somehow still exists, abort $this->type snapshot creation | tee -a $this->logfile");
          }
    }
    
    function verify()
    {
        if (file_exists("$this->snapshotdir/$this->type.$this->snapshots")) 
	{
	    passthru("echo error: $this->type.$this->snapshots already exists, no rotation of daily snapshot! | tee -a $this->logfile");
            return false;
        }
        else
        {
            return true;
        }
    }
}

class BTRFSIncrementalRotator extends IncrementalRotator
{
    
    function cleanup()
    {
        if(file_exists("$this->snapshotdir/$this->type.$this->snapshots"))
        {
            passthru("echo destroy $this->type... && btrfs subvolume delete $this->snapshotdir/$this->type.$this->snapshots");
            print "done destroying outdated snapshots\n";
        }
    }
    
    function snapshot()
    {
        if (!file_exists("$this->snapshotdir/$this->type.0"))
        {
            passthru("btrfs subvolume snapshot -r $this->snapshotsdir/rsync.tmp $this->snapshotdir/daily.0  | tee -a $this->logfile");
        }
        else
        {
            passthru("echo $this->type.0 somehow still exists, abort $this->type snapshot creation | tee -a $this->logfile");
        }
    }
}

class ZFSIncrementalRotator extends IncrementalRotator
{
    function __construct($settings)
    {
        parent::__construct($settings);

        #this will give you the file system mounted at $this->snapshotdir
        $this->zfs_fs = exe("zfs list $this->snapshotdir | sed -e 's/ .*//' | tail -n 1"); # tail cuts the columns headers
        # Real SnapShotDir
        $this->zfs_dir = ".zfs/snapshot/"; 
    }

    function cleanup()
    {
        if (file_exists("$this->snapshotdir/$this->zfs_dir$this->type.$this->snapshots"))
        {
            passthru("echo destroy $this->type... && btrfs subvolume delete $this->snapshotdir/$this->type.$this->snapshots");
            print "done destroying outdated snapshots\n";
        }
    }

    function rename()
    {
        for($i=($this->snapshots-1); $i>=0; $i--)
        {
            $ii = $i+1;
            if (file_exists("$this->snapshotdir/.zfs/snapshot/$this->type.$i")) 
	    {	
		passthru("rename $this->zfs_fs@$this->type.$i $this->zfs_fs@$this->type.$ii | tee -a $this->logfile");
            }
        }
    }
    
    function snapshot()
    {
        #this will give you the file system mounted at $this->snapshotdir
        passthru("zfs snapshot $this->zfs_fs@$this->type.0 | tee -a $this->logfile");
    }
    
    function verify()
    {
        if (file_exists("$this->snapshotdir/.zfs/snapshot/$this->type.$this->snapshots")) 
	{
	    passthru("echo error: /.zfs/snapshot/$this->type.$this->snapshots already exists, no rotation of daily snapshot! | tee -a $this->logfile");
            return false;
        }
        else
        {
            return true;
        }
    }
}
#####################################
# WEEKLY
#####################################	
class PeriodicRotator extends Rotator
{
    function __construct($settings, $period)
    {
        parent::__construct($settings);

        $this->period = $period;
    }
    
    function cleanup()
    {
        //move directory
        if ($this->settings['environment']['movedir'])
        {
            $timestamp = date('Y-m-d_H-i');
            print "moving old $this->type snapshot to configured directory...";
            passthru("$this->mv -v $this->snapshotdir/$this->type.$this->snapshots $this->settings['environment']['movedir']/$this->settings['remote']['host']/$this->type.$this->snapshots.$timestamp");
            print "done\n";
        }
        else
        {
            print "deleting old $this->type snapshots...\n";
            passthru("$this->rm -Rf $this->snapshotdir/$this->type.$this->snapshots");
            print "done\n";
        }
    }
    
    function rename()
    {
        $found = exe("find $this->snapshotdir/ -maxdepth 1 -type d -name $this->type.0 -mtime -7"); 
        if(!$found)    
        {    
            passthru("echo no recent weekly snapshot found, creating one... | tee -a $this->logfile");
            # rotate previous weekly snapshots
            for($i=($this->snapshots - 1); $i>=0; $i--)
            {
                $y = $i + 1;
                passthru("echo DEBUG: checking presence of $this->snapshotdir/$this->$type.$y  | tee -a $this->logfile");
                if (file_exists("$this->snapshotdir/$this->type.$y")) # if not using zfs, ZFS_RSSD will be empty
                {
                    passthru("echo error: snapshot $this->type.$y already exists, cannot rename to it! | tee -a $this->logfile");
                    return;
                }

                if (file_exists("$this->snapshotdir/$this->type.$i"))
                {        
                    # on btrfs, this actually renames the subvolume, so no need for a separate command
                    passthru("$this->mv $this->snapshotdir/$this->type.$i $this->snapshotdir/$this->type.$y | tee -a $this->logfile");
                }
            }    
        }
    }
    
    function snapshot()
    {
          if (!file_exists("$this->snapshotdir/$this->type.0"))
          {
            # link-copy latest weekly snapshot 
            passthru("$this->cp -al $this->snapshotsdir/daily.0 $this->snapshotsdir/weekly.0 | tee -a $this->logfile");
            passthru("touch $this->snapshotsdir/weekly.0 | tee -a $this->logfile");
        }
          else
          {
              passthru("echo $this->type.0 somehow still exists, abort $this->type snapshot creation | tee -a $this->logfile");
          }
    }
    
    function verify()
    {
        $dir = (isset($this->zfs_dir))? $this->zfs_dir:'';
        $found = exe("find $this->snapshotsdir/$dir -maxdepth 1 -type d -name weekly.0 -mtime -7");
        if ($found)
	{
	    passthru("found weekly snapshot younger than a week, not creating weekly snapshot | tee -a $this->logfile");
            return false;
        }
        else
        {
            return true;
        }
    }
}

class BTRFSPeriodicRotator extends PeriodicRotator
{
    function cleanup()
    {
        if(file_exists("$this->snapshotdir/$this->type.$this->snapshots"))
        {
            passthru("echo destroy $this->type... && btrfs subvolume delete $this->snapshotdir/$this->type.$this->snapshots");
            print "done destroying outdated snapshots\n";
        }
    }
    
    function snapshot()
    {
        if (!file_exists("$this->snapshotdir/$this->type.0"))
        {
            passthru("btrfs subvolume snapshot -r $this->snapshotsdir/daily.0 $this->snapshotdir/weekly.0  | tee -a $this->logfile");
        }
        else
        {
            passthru("echo $this->type.0 somehow still exists, abort $this->type snapshot creation | tee -a $this->logfile");
        }
    }
}

class ZFSPeriodicRotator extends PeriodicRotator
{
    function __construct($settings)
    {
        parent::__construct($settings);

        #this will give you the file system mounted at $this->snapshotdir
        $this->zfs_fs = exe("zfs list $this->snapshotdir | sed -e 's/ .*//' | tail -n 1"); # tail cuts the columns headers
        # Real SnapShotDir
        $this->zfs_dir = ".zfs/snapshot/"; 
    }

    function cleanup()
    {
        if (file_exists("$this->snapshotdir/$this->zfs_dir$this->type.$this->snapshots"))
        {
            passthru("echo destroy $this->type... && btrfs subvolume delete $this->snapshotdir/$this->type.$this->snapshots");
            print "done destroying outdated snapshots\n";
        }
    }
    
    function rename()
    {
        $dir = $this->zfs_dir;
        $found = exe("find $this->snapshotdir/$dir -maxdepth 1 -type d -name $this->type.0 -mtime -7"); 
        if(!$found)    
        {    
            passthru("echo no recent weekly snapshot found, creating one... | tee -a $this->logfile");
            # rotate previous weekly snapshots
            for($i=($this->snapshots - 1); $i>=0; $i--)
            {
                $y = $i + 1;
                passthru("echo DEBUG: checking presence of $this->snapshotdir/$dir$this->$type.$y  | tee -a $this->logfile");
                if ( file_exists("$this->snapshotdir/$dir$this->type.$y")) # if not using zfs, ZFS_RSSD will be empty
                {
                    passthru("echo error: snapshot $this->type.$y already exists, cannot rename to it! | tee -a $this->logfile");
                    return;
                }
                passthru("zfs rename $this->zfs_fs@$this->typey.$i $this_zfs_fs@$this->type.$y | tee -a $this->logfile");
            }    
        }
    }
    
    function snapshot()
    {
        #this will give you the file system mounted at $this->snapshotdir
        passthru("zfs snapshot $this->zfs_fs@$this->type.0 | tee -a $this->logfile");
    }
}
