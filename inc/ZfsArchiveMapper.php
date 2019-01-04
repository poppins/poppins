<?php
//require parent class
require_once(dirname(__FILE__).'/ArchiveMapper.php');

class ZfsArchiveMapper extends ArchiveMapper
{
    /**
     * Function will scan for each subdirectory in /archive
     *
     * @param $validate Validate if unknown files or directories
     * @return array The directories
     */
    function get_archive_dirs()
    {
        // dirs
        $archive_dirs = [];

        //full path
        $dir = $this->archive_dir;

        if(is_dir($dir))
        {
            $archive_dirs []= $dir;
        }

        return $archive_dirs;
    }


    /**
     * @param $archive_dir
     * @return array
     */
    function get_clean_files($archive_dir)
    {
        //create whitelist for validation
        $clean_files = [];

        // iterate through all snapshots
        foreach (scandir($archive_dir) as $found)
        {
            //check if dir
            $prefix = str_replace('.', '\.', $this->Config->get('local.hostdir-name'));
            if (is_dir("$archive_dir/$found"))
            {
                if (preg_match("/$prefix\.$this->dir_regex\.poppins$/", $found))
                {
                    // add to whitelist
                    $clean_files [] = $found;
                }
            }
        }

        return $clean_files;
    }

    function get_snapshots_per_category()
    {
        $snaphots = [];

        foreach($this->whitelist as $path => $files)
        {
            $snapshots['all'] = $files;
        }

        return $snapshots;
    }

    function validate_archive_dir($archive_dir)
    {
        //this check is already done in the Application class.
        return;
//        $whitelist = $this->get_clean_files($archive_dir);
//        $this->whitelist[$archive_dir] = $whitelist;
//
//        $unclean_files = Validator::get_unclean_files($archive_dir, $whitelist, false);
//
//        if (count($unclean_files))
//        {
//            foreach ($unclean_files as $file => $type)
//            {
//                $this->messages []= "Archive subdirectory $archive_dir not clean, unknown $type '$file'. Remove or rename to '_$file'..";
//            }
//        }
    }
}