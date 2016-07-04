# RELEASE 0.2

DATE: 2016-07-01

## NEW FEATURES

### INI FILE

The following ini configuration directives need to be ADDED to the config file.
See example.poppins.ini:

        ...
        [local]
        ; snapshots are created using hardlinks by default
        ; if available, you may use zfs or btrfs
        ; filesystem snapshots instead
        ; values are "default", "btrfs" or "zfs"
        snapshot-backend = "btrfs"
        ...
        [remote]
        ; enable remote connection with ssh, default is yes
        ; disable if backing up local directories (e.g. to an external drive)
        ; host and user directives are disregarded in case ssh is disabled
        ssh = yes
        ; ssh connection retries if desired
        ; check is applied on first connection attempt
        ; default is 0
        retry-count = 0
        ; timeout between retries in seconds
        ; default is 0
        retry-timeout = 10
        ...
        
The following directives need to be REMOVED:

        ...
        ; local filesystem options: default/ZFS/BTRFS
        ; use ZFS or BTRFS if you want to use shapshot features
        ; for these filesystems. Otherwise, use default.
        filesystem = 'BTRFS'
        ...: 
        
### NEW CLI OPTIONS

The following cli options have been added:

* -color: add colors to cli output
* -t {tag}: add an optional tag to poppins log file

### TAGGING

You may add a tag to a poppins run, e.g. a hash or timestamp:

    poppins -c example.poppins.ini -t POPPINS.RUN.$(date +%Y%m%d)
        
If runs are tagged, you can search through your log files, e.g. search warnings or errors:

    zgrep -l POPPINS.RUN.20160624 /home/poppins/poppins.d/logs/*gz | grep -E 'warning|error' | xargs zcat
    
In cron you can add a timestamp like so:

        TIMESTAMP="date +%Y%m%d"
        # m h  dom mon dow   command
        1 1 * * * /usr/local/bin/poppins -c /home/poppins/poppins.d/conf/example.poppins.ini -t POPPINS.RUN.$($TIMESTAMP)
        
## WHAT HAS CHANGED?

### MAJOR CHANGES

* The "filesystem" directive in the [local] section was removed as it is too
ambiguous. It is replaced by the "snapshot-backend" directive. Rotation logic is
not necessarily related to filesystem.
* Local rsync (no ssh connection) is supported, enabling you to schedule a
backup on your local machine. E.g. using an external drive for backups.
* Ssh connection attempts and retry timeouts implemented.
* Strong validation of the ini file was added. 
    * Illegal or potentially dangerous characters (such as '*') are not allowed.
    * Quotes in 'yes' and 'no' are deprecated. Do not use quotes in booleans.

### MINOR CHANGES

* Allow unicode characters in the ini file.
* Warn if host directories (e.g. rsync dir, archive dir, snapshot dirs, mysql dir) contain unknown (not configured) files/directories.
* Better reporting: disk usage, snapshot list, ...

## WHAT HAS BEEN FIXED?

* Fixed issues with spaces in file names
* Fixed issues with options overriding
* Output pre-backup-script if script failed
* Refactoring and other small bug fixing

## KNOWN ISSUES

* Validation of ini files is imperfect because of the lack of type support of 
the function parse_ini_file(). As of PHP 5.6, INI_SCANNER_TYPED needs to be 
implemented when PHP 5.6+ is available on Debian and CentOS latest releases.

## INSTALLATION

Run command:

    hg pull -u

# RELEASE 0.1

DATE: 2015-10-05

(Initial release)
