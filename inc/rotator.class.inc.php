<?php

class Rotator
{
    protected $App;
      
    protected $settings;
    
    protected $destdir;
    
    function __construct($App)
    {
        $this->App = $App;
        
        $this->settings = $App->settings;
        
        $this->cdatestamp = date('Y-m-d.His', $this->App->start_time);
        $this->destdir = $this->settings['remote']['host'].'.'.$this->cdatestamp.'.poppins';
    }
    
    function init()
    {
        $arch2 = $this->read_dir();
        #####################################
        # DATA SORTEREN
        #####################################
        foreach ($arch2 as $k => $v)
        {
            if (count($arch2[$k]))
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
        }
        #####################################
        # AFLOPEN
        #####################################
        foreach ($arch2 as $k => $v)
        {
            if ($k == 'incremental')
            {
                $arch2[$k] [] = $this->destdir;
            }
            else
            {
                if ($end[$k])
                {
                    if (!preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}/', $end[$k], $m))
                    {
                        $this->App->fail("Wrong dirstamp format found, cannot contionue!");
                    }
                    else
                    {
                        $ddatestamp = $m[0];
                        $cdatestamp2unix = $this->to_time($this->cdatestamp);
                        $ddatestamp2unix = $this->to_time($ddatestamp);
                        if ($cdatestamp2unix < $ddatestamp2unix)
                        {
                            $this->App->fail('Cannot continue. Newer dir found: ' . $ddatestamp);
                        }
                        else
                        {
                            $diff = $cdatestamp2unix - $ddatestamp2unix;
                            if (time_exceed($diff, $k))
                            {
                                $arch2[$k] [] = $this->destdir;
                            }
                        }
                    }
                }
                else
                {
                    $arch2[$k] [] = $this->destdir;
                }
            }
        }

        #####################################
        # SLICE
        #####################################
        echo "-----------RESULT----------------\n";
        foreach ($arch2 as $k => $v)
        {
            $n = $settings['snapshots'][$k];
            $arch2[$k] = array_slice($arch2[$k], -$n, $n);
            echo "\n[$k]\n";
            foreach ($arch2[$k] as $vv)
                echo "$vv, ";
        }
        #####################################
        # COMPARE ARCH TO RES
        #####################################
        $add = [];
        $remove = [];

        foreach ($arch2 as $k => $v)
        {
            if (count($v))
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
    }

    function to_time($stamp, $format = 'unix')
    {
        $t = explode('_', $stamp);
        $date = $t[0];
        $time = implode(':', str_split($t[1], 2));
        $datetime = $date . ' ' . $time;
        switch ($format)
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
        //check types
        if (!in_array($type, $this->App->intervals))
        {
            $this->App->fail('Interval not supported!');
        }
        //check if integer, else fail!
        if (!is_integer($diff))
        {
            $this->App->fail('Cannot compare dates if no integer!');
        }
        $seconds['hourly'] = 60 * 60;
        $seconds['daily'] = $seconds['hourly'] * 24;
        $seconds['weekly'] = $seconds['daily'] * 7;
        $seconds['monthly'] = $seconds['daily'] * 30;
        $seconds['yearly'] = $seconds['daily'] * 365;
        return (boolean) ($diff >= $seconds[$type]);
    }

}

class DefaultRotator extends Rotator{}

class BTRFSRotator extends Rotator{}

class ZFSRotator extends Rotator{}
