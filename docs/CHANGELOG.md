# RELEASE 0.5

DATE: 2019-12-23

## NEW FEATURES

## WHAT HAS CHANGED?

The Poppins repository has been moved to github. Mercurial is no longer supported.

------------------------------------------------------------------------------------------------------------------------
# RELEASE 0.4

DATE: 2019-07-19

## NEW FEATURES

### Ini file

The following ini configuration directives need to be ADDED to the config file.

See example.poppins.ini:

    ...
    [remote]
    ; Ssh remote port, default is 22
    port = 22
    ...
    [meta]
    ...
    ; include restore scripts
    restore-scripts = yes
    ...
    [rsync]
    ...
    ; add timestamps to every line of rsync output
    timestamps = yes
    ...
    [mysql]
    ...
    ; include specific databases
    ; not supported in combination with multiple .my.cnfs
    ; you may use a regular expressions (Perl synxtax) within slashes "/"
    ; e.g. '/^drupal_.*/' # all databases starting with string 'drupal_'
    ; default is every database except information_schema
    ; included-databases = 'wordpress_1,wordpress_2,/^drupal_.*$/'
    
    ; exclude databases - see previously
    ; excluded-databases = '/^drupal_test.*/'
    
    ; ignore specific tables within the databases
    ; Use dot notation "database.table" or regex "/<REGEX>/" (Perl synxtax)
    ; e.g. '/database1\.tbl.*/' # all tables in database1 starting with string tbl
    ; default is every table except mysql.event
    ; ignore-tables = '/^wordpress1\.test_.+/'
    
    ; include specific tables in "tables" or "csv" output
    ; not supported in combination with multiple .my.cnfs
    ; Use dot notation "database.table" or regex "/<REGEX>/" (Perl synxtax)
    ; e.g. '/^database1\.tbl.*$/' # all tables in database1 starting with string tbl
    ; included-tables = '/^wordpress4\.tbl_.+/'
    
    ; exclude tables - see previously
    ; excluded-tables = ''
    
    ; output types: databases dumps, table dumps or tab seperated file (csv)
    ; use values database, table or csv (or combination)
    output = 'database,table,csv'

## WHAT HAS CHANGED?

### Major Changes

* Include or exclude specific databases or tables, based on regular expressions.
* Database export options: database dumps, table dumps or csv export.
* Added restore scripts to help with restoring backups.
* Keep snapshots with underscore indefinitely. Ignore these snapshots from rotation.

### Minor Changes

* Type support/validation enabled in config file
* Rsync error 24 triggers a notice, no longer a warning.
* Show version hash when calling Poppins version.
* Run remote ssh session on a different port.

## WHAT HAS BEEN FIXED?

* Small bugfixes and cosmetic changes

## KNOWN ISSUES

## INSTALLATION

To upgrade, run following command in the poppins directory:

    hg pull -u

------------------------------------------------------------------------------------------------------------------------
# RELEASE 0.3

DATE: 2017-06-30

## NEW FEATURES

### Ini file

The following ini configuration directives need to be ADDED to the config file.

See example.poppins.ini:

    [remote]
    ...
    ; if the backup job fails, abort or continue with the post-backup script
    ; values are "abort" or "continue"
    backup-onfail = "abort";
    ; remote script ran after backup
    ; e.g. post-backup-script = "/home/poppins/some-post-backup-script.sh";
    post-backup-script = "";
    ...
    [rsync]
    ...
    ; cross filesystem boundaries
    cross-filesystem-boundaries = no

## WHAT HAS CHANGED?

### Major Changes

* Directive "cross-filesystem-boundaries" is added to the rsync section. A warning is triggered when trying to cross mounted filesystem boundaries if this option is set to "no", which is the default. You must explicitly exclude these directories or set the option to "yes".
* Notices are introduced. Less important messages (dir not clean, incomplete configuration, duplicate mysql config file) will be considered notices rather than warnings.
* Post backup script added. May or not be run depending on a successful rsync run.

### Minor Changes

* Cleanup script added to remove old log files. See scripts directory.
* Validation of trailing slashes in exluded/included sections for consistency.
* Validation of relative paths in excluded section as the exclude path is always treated as a relative path by rsync.

## WHAT HAS BEEN FIXED?

* Small bugfixes and cosmetic changes

## KNOWN ISSUES

* Validation of ini files is imperfect because of the lack of type support of
the function parse_ini_file(). As of PHP 5.6, INI_SCANNER_TYPED needs to be
implemented when PHP 5.6+ is available on Debian and CentOS latest releases.

## INSTALLATION

To upgrade, run following command in the poppins directory:

    hg pull -u

------------------------------------------------------------------------------------------------------------------------
# RELEASE 0.2

DATE: 2016-07-01

## NEW FEATURES

### Ini file

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
    ...

### New cli options

The following cli options have been added:

* --color: add colors to cli output
* -t {tag}: add an optional tag to poppins log file

### Tagging

You may add a tag to a poppins run, e.g. a hash or timestamp:

    poppins -c example.poppins.ini -t POPPINS.RUN.$(date +%Y%m%d)

If runs are tagged, you can search through your log files, e.g. search warnings or errors:

    zgrep -l POPPINS.RUN.20160624 /home/poppins/poppins.d/logs/*gz | grep -E 'warning|error' | xargs zcat

In cron you can add a timestamp like so:

    TIMESTAMP="date +%Y%m%d"
    # m h  dom mon dow   command
    1 1 * * * /usr/local/bin/poppins -c /home/poppins/poppins.d/conf/example.poppins.ini -t POPPINS.RUN.$($TIMESTAMP)

## WHAT HAS CHANGED?

### Major Changes

* The "filesystem" directive in the [local] section was removed as it is too ambiguous. It is replaced by the "snapshot-backend" directive. Rotation logic is not necessarily related to filesystem.
* Local rsync (no ssh connection) is supported, enabling you to schedule a backup on your local machine. E.g. using an external drive for backups.
* Ssh connection attempts and retry timeouts implemented.
* Strong validation of the ini file was added.
    * Illegal or potentially dangerous characters (such as '*') are not allowed.
    * Quotes in 'yes' and 'no' are deprecated. Do not use quotes in booleans.

### Minor Changes

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

------------------------------------------------------------------------------------------------------------------------
# RELEASE 0.1

DATE: 2015-10-05

(Initial release)
