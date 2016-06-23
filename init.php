#!/usr/bin/php
<?php
#####################################
# LIB
#####################################
require_once('inc/lib.inc.php');
##################################
# SETTINGS
#####################################
// In Mary Poppins, Mary, Bert, and the children ride a merry-go-round, then leave
// the carousel on their horses to go off on a fox hunt and a horse race.
$Settings = Settings::get_instance();
// app name
$Settings->set('appname', 'Poppins');
// set start time
$Settings->set('start_time', date('U'));
// version
$mercurial_version = shell_exec('cd "'.dirname(__FILE__).'";hg parent --template "{rev}" 2>/dev/null');
$poppins_version = '0.2';
$full_version = ($mercurial_version)? $poppins_version.'.'.$mercurial_version:$poppins_version;
$Settings->set('version', $full_version);
// supported intervals
$Settings->set('intervals', ['incremental', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'yearly']);
#####################################
# APPLICATION
#####################################
$App = new Application();
$App->init();
#####################################
# BACKUPS
#####################################
$App->out('Initiate backups', 'header');
//initiate
$c = BackupFactory::create($App);
$c->init();
#####################################
# ROTATE
#####################################
//initiate
$c = RotatorFactory::create($App);
$c->init();
#####################################
# END
#####################################
$App->succeed();
