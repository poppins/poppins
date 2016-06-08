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
$full_version = ($mercurial_version)? '0.1.'.$mercurial_version:'0.1';
$Settings->set('version', $full_version);
// supported intervals
$Settings->set('intervals', ['incremental', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'yearly']);
#####################################
# APPLICATION
#####################################
$App = new Application();
$App->init();
////$Config = new Config();
//$Config = Config::get_instance();
//$s['familie']['goussy']['kinderen'] = ['Nette', 'Ebba'];
//$Config->store($s);
////var_dump($Config->get_all());
//
//$Config->set(['familie', 'dooms1', 'kinderen'], ['Haroun', 'Rania']);
//$Config->set(['familie', 'peeraerts', 'kinderen'], ['Liesbet', 'Stijn']);
//$Config->set(['familie', 'peeraerts', 'kinderen'], ['Liesbet', 'Stijn', 'Karen']);
////var_dump($Config->get_all());
//
//$Config->set('familie.dooms2.kinderen', ['Elmer', 'Stik']);
//$Config->set('familie.dooms2.kinderen', ['Elmer', 'Stig']);
//
//$mama = $Config->get('mama', false);
//var_dump($mama);
//if($mama)
//{
//    echo 'mama';
//}
//else
//{
//    echo 'geen mama';
//}
//var_dump($Config->get());
//var_dump($Config->get('familie.dooms1.kinderen'));
//var_dump($Config->get(['familie', 'dooms1', 'kinderen']));
//die();
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
