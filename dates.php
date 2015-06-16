<?php
function to_time($stamp, $format = 'unix')
{
    $t = explode('_', $stamp);
    $date = $t[0];
    $time = implode(':', str_split($t[1], 2));
    $datetime = $date . ' ' . $time;
    switch($format)
    {
        case 'unix':
            $result = strtotime($datetime);
            break;
        case 'string':
            $result = date($datetime);
            break;
    }
    return $result;
}
//check if a diff exceeds a himan readable value
function time_exceed($diff, $type)
{
    global $DIRS;
    if(!in_array($type, $DIRS))
    {
        die('Period not supported!');
    }
    if(!is_integer($diff))
    {
        die('Diff must be an integer!');
    }
    $seconds['hourly'] = 60*60;
    $seconds['daily'] = $seconds['hourly']*24;
    $seconds['weekly'] = $seconds['daily']*7;
    $seconds['monthly'] = $seconds['daily']*30;
    $seconds['yearly'] = $seconds['daily']*365;
    return (boolean) ($diff >= $seconds[$type]);
}

$DIRS = ['incremental', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'];
//initiate arrays
foreach($DIRS as $c)
{
    $arch[$c] = [];
    $res[$c] = [];
}
//$arch ['incremental'] []= 'jessie2.2015-05-31_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-01_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-02_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-03_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-04_170100.poppins';

//$arch ['incremental'] []= 'jessie2.2015-06-05_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-06_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-07_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-08_170100.poppins';
//$arch ['incremental'] []= 'jessie2.2015-06-09_170100.poppins';
//
//$arch ['hourly'] []= 'jessie2.2015-06-07_170100.poppins';
//$arch ['hourly'] []= 'jessie2.2015-06-09_170100.poppins';
//$arch ['hourly'] []= 'jessie2.2015-06-08_170100.poppins';
//
//$arch ['daily'] []= 'jessie2.2015-06-07_170100.poppins';
//$arch ['daily'] []= 'jessie2.2015-06-08_170100.poppins';
//$arch ['daily'] []= 'jessie2.2015-06-09_170100.poppins';
//
//$arch ['weekly'] []= 'jessie2.2015-06-09_170100.poppins';
//
//$arch ['monthly'] []= 'jessie2.2015-05-31_170100.poppins';
//$arch ['monthly'] []= 'jessie2.2015-06-01_170100.poppins';

//$arch ['yearly'] = [];
//$arch ['yearly'] []= 'jessie2.2015-06-09_170100.poppins';

$res = $arch;

$settings['snapshots']['incremental'] = 1;
$settings['snapshots']['hourly'] = 0;
$settings['snapshots']['daily'] = 0;
$settings['snapshots']['weekly'] = 0;
$settings['snapshots']['monthly'] = 0;
$settings['snapshots']['yearly'] = 0;

$cdate = '2017-06-10_180200';
$newdir = 'jessie2.' . $cdate . '.poppins';
echo "CURRENT DATE IS $cdate\n";

#####################################
# SORTEREN
#####################################
//sort alphabtically
echo "\n-----------ORIGINAL----------------\n";
foreach ($res as $k => $v)
{
    echo "\n[$k]\n";
    if(count($res[$k]))
    {
        asort($res[$k]);
        $base[$k] = $res[$k][0];
        $end[$k] = (end($res[$k]));
    }
    else
    {
        $base[$k] = '';
        $end[$k] = '';
    }
    //
    foreach ($res[$k] as $vv)
    {
        echo "$vv, ";
    }    
}
#####################################
# AFLOPEN
#####################################
foreach ($res as $k => $v)
{
    if ($k == 'incremental')
    {
        $res[$k] [] = $newdir . '.NEW';
        if($settings['snapshots'][$k]) $move[$k] = true;
    }
    else
    {
        if ($end[$k])
        {
            if (!preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}/', $end[$k], $m))
            {
                die("WRONG FORMAT, CANNOT CONTINUE!");
            }
            else
            {
                $folderstamp = $m[0];
                $diff = to_time($cdate) - to_time($folderstamp);
                if (time_exceed($diff, $k))
                {
                    $res[$k] [] = $newdir . '.NEW';
                    if($settings['snapshots'][$k])  $move[$k] = true;
                }
            }
        }
        else
        {
            $res[$k] [] = $newdir . '.NEW';
            if($settings['snapshots'][$k]) $move[$k] = true;
        }
    }
}

#####################################
# SLICE
#####################################
echo "-----------RESULT----------------\n";
foreach($res as $k => $v)
{
    $n = $settings['snapshots'][$k];
    $res[$k] = array_slice($res[$k], -$n, $n);
    echo "\n[$k]\n";
    foreach($res[$k] as $vv) echo "$vv, ";
}
#####################################
# COMPARE ARCH TO RES
#####################################
$add = [];
$remove = [];

foreach($res as $k => $v)
{
    if(count($v))
    {
        foreach ($v as $vv)
        {
            $remove[$k] = array_diff($arch[$k], $res[$k]);
            $add[$k] = array_diff($res[$k], $arch[$k]);
        }
    }
    else
    {
        $remove[$k] = $arch[$k];
    }
}
    
echo "\n-----------REMOVE----------------\n";
if (count($remove))
{
    foreach ($remove as $k => $v)
    {
        if (count($v))
        {
            foreach ($v as $vv)
                print "remove $vv from $k\n";
        }
    }
}
echo "--------------ADD-----------------\n";
if (count($add))
{
    foreach ($add as $k => $v)
    {
        if (count($v))
        {
            foreach ($v as $vv)
                print "add $vv to $k\n";
        }
    }
}
echo "\n";

