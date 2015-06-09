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
# RSYNC BACKUPS
#####################################
//initiate
$c = BackupFactory($App);
$c->Cmd = $Cmd;
$c->App = $App;
$c->init();
#####################################
# ROTATE
#####################################
foreach (['daily', 'weekly'] as $interval)
{
    //initiate
    $c = RotatorFactory($interval, $_settings);
    $c->init();
}
#####################################
# REMOVE LOCK
#####################################
#delete lock
$Cmd->exe($_settings['cmd']['rm'] . " --verbose --force $SNAPDIR/LOCK | tee -a $LOGFILE");
$Cmd->exe("exit $failed"); # 1 is failed, 0 is OK

$App->succeed();
