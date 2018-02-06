#!/usr/bin/php
<?php
$_poppins_version = '0.3';
#####################################
# LIB
#####################################
require_once('inc/lib.inc.php');
##################################
# SETTINGS
#####################################
// In Mary Poppins, Mary, Bert, and the children ride a merry-go-round, then leave
// the carousel on their horses to go off on a fox hunt and a horse race.
$Session = Session::get_instance();
// app name
$Session->set('appname', 'Poppins');
// set start time
$Session->set('start_time', date('U'));
// version
$mercurial_version = shell_exec('cd "'.dirname(__FILE__).'";hg parent --template "{rev}" 2>/dev/null');
$full_version = ($mercurial_version)? $_poppins_version.'.'.$mercurial_version:$_poppins_version;
$Session->set('version', $full_version);
// supported intervals
$Session->set('intervals', ['incremental', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'yearly']);
#####################################
# APPLICATION
#####################################
$App = new Application();
$App->init();
#####################################
# BACKUPS
#####################################
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
$App->quit();
