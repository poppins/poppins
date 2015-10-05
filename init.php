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
$mercurial_version = shell_exec('cd "'.dirname(__FILE__).'";hg parent --template "{rev}" 2>/dev/null');
$VERSION = ($mercurial_version)? '0.1.'.$mercurial_version:'0.1';
###############################################################################################################
# LIBRARY
###############################################################################################################
require_once('inc/lib.inc.php');
#####################################
# APPLICATION
#####################################
$App = new Application($APPNAME, $VERSION);
$App->init();
###############################################################################################################
# BACKUPS
###############################################################################################################
$App->out('Initiate backups', 'header');
#####################################
# RSYNC
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
$App->succeed();
