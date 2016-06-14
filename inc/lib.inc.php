<?php

function dd($s = '')
{
    var_dump($s);
    die();
}
function d($s = '')
{
    var_dump($s);
}
//scan all files and require them
$files = scandir(dirname(__FILE__));
foreach($files as $file)
{
    if (preg_match('/class\.inc\.php$/', $file))
    {
        require_once $file;
    }
  
}