# README #
### Copyrightv###
Poppins 

Copyright (C) 2015 Free Software Foundation, Inc.
License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>.
This is free software: you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.

Written by Bruno Dooms, Frank Van Damme

### What is this repository for? ###
* Quick summary
Poppins - backup script with incremental snapshots. 

* Version:
PUppet version 1.91

### How do I get set up? ###
* Step 1
  Make sure following packages are installed on local machine: hg, php5-cli (Debian) or php-cli (RedHat), rsync, ssh, grep. 
* Step 2
  Make sure following packages are installed on remote machine: rsync, ssh, grep, aptitude (Debian) or yum-utils/rpm (Red Hat). 
* Step 3
  Make a link to init.php in /usr/local/bin, e.g.:
        ln -s ~/poppins/init.php /usr/local/bin/poppins

* Configuration
All configuration in {filename}.poppins.ini. See example.poppins.ini for indtructions.

### Deployment instructions ###

SYNOPSIS
    poppins -c {configfile} [-d] [-h] [-v] [--long-options]

DESCRIPTION
    Poppins has one required option, "-c", the configfile. Good practice is to name it {filename}.poppins.ini. Options in the ini file may be overridden by cli options. A useful overriden parameter is the [remote] host. it may be overridden like so: --remote-host={hostname} 

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

INSTALLATION

* Make sure following packages are installed on local machine: hg, php5-cli (Debian) or php-cli (RedHat), rsync, ssh, grep. 
* Make sure following packages are installed on remote machine: rsync, ssh, grep, aptitude (Debian) or yum-utils/rpm (Red Hat). 
* Make a link to init.php in /usr/local/bin, e.g.:
        ln -s ~/poppins/init.php /usr/local/bin/poppins