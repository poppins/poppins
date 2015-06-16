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
    print 'diff='.$diff."\n";
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
    $arch1[$c] = [];
    $arch2[$c] = [];
}

$arch1 ['incremental'] []= 'jessie2.2015-06-05_170100.poppins';
$arch1 ['incremental'] []= 'jessie2.2015-06-06_170100.poppins';
$arch1 ['incremental'] []= 'jessie2.2015-06-07_170100.poppins';
$arch1 ['incremental'] []= 'jessie2.2015-06-08_170100.poppins';
$arch1 ['incremental'] []= 'jessie2.2015-06-09_170100.poppins';

$arch1 ['hourly'] []= 'jessie2.2015-06-10_170100.poppins';
$arch1 ['hourly'] []= 'jessie2.2015-06-10_180100.poppins';
$arch1 ['hourly'] []= 'jessie2.2015-06-10_190100.poppins';

//$arch1 ['daily'] []= 'jessie2.2015-06-07_170100.poppins';
//$arch1 ['daily'] []= 'jessie2.2015-06-08_170100.poppins';
//$arch1 ['daily'] []= 'jessie2.2015-06-09_170100.poppins';
//
//$arch1 ['weekly'] []= 'jessie2.2015-06-09_170100.poppins';
//
//$arch1 ['monthly'] []= 'jessie2.2015-05-31_170100.poppins';
//$arch1 ['monthly'] []= 'jessie2.2015-06-01_170100.poppins';

//$arch1 ['yearly'] = [];
//$arch1 ['yearly'] []= 'jessie2.2015-06-09_170100.poppins';

//copy archive 1
$arch2 = $arch1;

$settings['snapshots']['incremental'] = 3;
$settings['snapshots']['hourly'] = 5;
$settings['snapshots']['daily'] = 0;
$settings['snapshots']['weekly'] = 0;
$settings['snapshots']['monthly'] = 0;
$settings['snapshots']['yearly'] = 0;

$cdate = '2015-06-07_180200';

//echo variable
$newdir = 'jessie2.' . $cdate . '.poppins';
echo "CURRENT DATE IS $cdate\n";

#####################################
# SORTEREN
#####################################
//sort alphabtically
echo "\n-----------ORIGINAL----------------\n";
foreach ($arch2 as $k => $v)
{
    echo "\n[$k]\n";
    if(count($arch2[$k]))
    {
        asort($arch2[$k]);
        $base[$k] = $arch2[$k][0];
        $end[$k] = (end($arch2[$k]));
    }
    else
    {
        $base[$k] = '';
        $end[$k] = '';
    }
    //
    foreach ($arch2[$k] as $vv)
    {
        echo "$vv, ";
    }    
}
#####################################
# AFLOPEN
#####################################
foreach ($arch2 as $k => $v)
{
    if ($k == 'incremental')
    {
        $arch2[$k] [] = $newdir . '.NEW';
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
                $ddate = $m[0];
                $cdate2unix = to_time($cdate);
                $ddate2unix = to_time($ddate);
                if($cdate2unix < $ddate2unix)
                {
                    die('Newer dir exists: '.$ddate);
                }
                else
                {
                    $diff = $cdate2unix - $ddate2unix;
                    if (time_exceed($diff, $k))
                    {
                        $arch2[$k] [] = $newdir . '.NEW';
                        if($settings['snapshots'][$k])  $move[$k] = true;
                    }
                }
            }
        }
        else
        {
            $arch2[$k] [] = $newdir . '.NEW';
            if($settings['snapshots'][$k]) $move[$k] = true;
        }
    }
}

#####################################
# SLICE
#####################################
echo "-----------RESULT----------------\n";
foreach($arch2 as $k => $v)
{
    $n = $settings['snapshots'][$k];
    $arch2[$k] = array_slice($arch2[$k], -$n, $n);
    echo "\n[$k]\n";
    foreach($arch2[$k] as $vv) echo "$vv, ";
}
#####################################
# COMPARE ARCH TO RES
#####################################
$add = [];
$remove = [];

foreach($arch2 as $k => $v)
{
    if(count($v))
    {
        foreach ($v as $vv)
        {
            $remove[$k] = array_diff($arch1[$k], $arch2[$k]);
            $add[$k] = array_diff($arch2[$k], $arch1[$k]);
        }
    }
    else
    {
        $remove[$k] = $arch1[$k];
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

