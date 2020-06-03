#!/usr/bin/php
<?php
$_poppins_version = '0.5';
#####################################
# LIB
#####################################
require_once('inc/lib.inc.php');
#####################################
# SETTINGS
#####################################
// In Mary Poppins, Mary, Bert, and the children ride a merry-go-round, then leave
// the carousel on their horses to go off on a fox hunt and a horse race.
$Session = Session::get_instance();
// app name
$Session->set('appname', 'Poppins');
// set start time
$Session->set('chrono.session.start', date('U'));
#####################################
# VERSION
#####################################
// check if git is installed
$git_installed = (!empty(trim(shell_exec('git --version 2>/dev/null;'))))? true:false;
// display full version if git is installed
if($git_installed)
{
    # output the number of commits
    $git_commits = trim(shell_exec('cd "'.dirname(__FILE__).'"; git rev-list HEAD | wc -l 2>/dev/null;'));

    # git branch
    $git_branch = trim(shell_exec('cd "'.dirname(__FILE__).'";git rev-parse --abbrev-ref HEAD 2>/dev/null;'));
    # do not output in case dettached head
    $git_branch = ($git_branch == 'HEAD')? '---':$git_branch;

    # the commit hash
    $git_hash = trim(shell_exec('cd "'.dirname(__FILE__).'"; git rev-parse --short HEAD 2>/dev/null;'));
    // full version
    $full_version = $_poppins_version.'.'.$git_commits.' '.$git_branch.' '.$git_hash;
}
// display short version
else
{
    $full_version = $_poppins_version.' (install git to display full version!)';
}
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
