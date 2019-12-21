# README #
### Copyright ###
Poppins 

Copyright (C) 2015 Free Software Foundation, Inc.
License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>.
This is free software: you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.

Written by Bruno Dooms, Frank Van Damme

### Summary ###
* Quick summary: Poppins - backup script with incremental snapshots. 
* Version: Poppins version 0.2 beta

### Install ###
Step 1. Make sure following packages are installed on the backup server: 

    git, php5-cli (php-cli), rsync, ssh, grep, gzip, moreutils

Step 2. Verify if the timezone is configured correctly in php. Look for a php.ini file in the /etc directory. For example:

    date.timezone = Europe/Brussels

Step 3. Download the source code with the git command. 

    git clone https://github.com/poppins/poppins.git /opt/poppins

Step 4. Make a link to init.php in /usr/local/bin.

    ln -s /opt/poppins/init.php /usr/local/bin/poppins

Step 5. Verify the installation.  

    poppins -v

Step 6. Make sure following packages are installed on remote machine: 

    rsync, ssh, grep, aptitude (Debian) or yum-utils/rpm (Red Hat). 

Step 7. Establish a passwordless ssh login to the client using ssh-keygen & ssh-copy-id.

### mysql ###
If using mysql backups, credentials must be provided in the .my.cnf file. See config.

### Upgrade ###
Navigate to the poppins source directory and pull the code with the git command: 

    git pull


### Configuration ###
All configuration in {filename}.poppins.ini. See example.poppins.ini for instructions.

### Deployment ###
SYNOPSIS

    poppins -c {configfile} [--color] [-d] [-h] [-n] [-t {tag}] [-v] [--long-options]

DESCRIPTION

Poppins has one required option, "-c", the configfile. An example can be found in the poppins source directory. You must name it {filename}.poppins.ini. Options in the ini file may be overridden by cli options. See example.

EXAMPLES

    poppins -c example.poppins.ini --remote-host=webserver1 --color

OPTIONS

    -c {configfile}
        Required configuration file.

     --color
         Output in pretty colors.

    -d
        Debugmode. Output all commands ran by Poppins.

    -h, --help
        Print this help page.

    -t {tag}
        Add a tag to the application log file.
        
    -n
        Dry run

    -v
        Print Poppins version.

### Cleaning up old log files ###

SYNOPSIS

    scripts/logdir.cleanup.sh  -l {logdir} [ -a ]

DESCRIPTION

This script serves the purpose of cleaning up log old log files. Once old snapshots are rotated away, the log files from the backup jobs that created them are no longer useful. logdir.cleanup.sh will analyze the log files of succeeded backups, and propose to delete those whose snapshots have disappeared from your system. Optionally, it will also let you remove the logs of failed backups.

Take care when removing logs from backups that ended with an ERROR state! They might contain useful information about how or why your backup failed. Since a backup job in ERROR state never has snapshots (Poppins does not create snapshots based on a failed backup), logdir.cleanup.sh has no way of telling which log files are old enough to delete!

EXAMPLES

    logdir.cleanup.sh -l /root/poppins.d/logs 
    logdir.cleanup.sh -l /var/log/poppins -a

OPTIONS

    -l {logdir}
         Required: directory where you configured Poppins to save its log files.

    -a
        Automatic: old log files will be deleted, but logs of failed backup jobs will be left alone.

