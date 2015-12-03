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
* Version: Puppet version 0.1

### Install ###
Step 1. Download the source code with the hg command. 

    hg clone https://bitbucket.org/poppins/poppins /opt/poppins

Step 2. Make a link to init.php in /usr/local/bin.

    ln -s /opt/poppins/init.php /usr/local/bin/poppins

Step 3. Verify if the timezone is configured correctly in php. Look for a php.ini file in the /etc directory. For example:

    date.timezone = Europe/Brussels

Step 4. Make sure following packages are installed on local machine: 

    hg, php5-cli (Debian) or php-cli (RedHat), rsync, ssh, grep, gzip. 

Step 5. Make sure following packages are installed on remote machine: 

    rsync, ssh, grep, aptitude (Debian) or yum-utils/rpm (Red Hat). 

Step 6. Establish a passwordless ssh login to the client using ssh-keygen.

Step 7. Verify the installation.  

    poppins -v

### Upgrade ###
Navigate to the poppins source directory and pull the code with the hg command: 

    hg pull -u


### Configuration ###
All configuration in {filename}.poppins.ini. See example.poppins.ini for instructions.

### Deployment ###
SYNOPSIS

    poppins -c {configfile} [-d] [-h] [-v] [--long-options]

DESCRIPTION

Poppins has one required option, "-c", the configfile. An example can be found in the poppins source directory. You must name it {filename}.poppins.ini. Options in the ini file may be overridden by cli options. See example.

EXAMPLES

    poppins -c example.poppins.ini --remote-host=webserver1

OPTIONS

    -c {configfile}
        configfile. Required. 

    -d 
        debugmode. Will output all commands ran by poppins

    -h, --help
        prints this help page

    -v
        prints poppins version
