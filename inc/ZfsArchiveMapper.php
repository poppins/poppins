<?php
//require parent class
require_once(dirname(__FILE__).'/ArchiveMapper.php');

class ZfsArchiveMapper extends ArchiveMapper
{
    /**
     * Function will scan for each subdirectory in /archive. In ZFS this is just one directory
     *
     * @param $validate Validate if unknown files or directories
     * @return array The directories
     */
    function get_archive_dirs()
    {
        // just one dir in ZFS case
        return [$this->Config->get('local.hostdir') . '/archive'];

    }

    /**
     * Get all snapshots per type
     *
     * @return array
     */
    function map()
    {
        $map = [];

        $snapshots = $this->snapshots;
        reset($snapshots);
        $path = key($snapshots);

        //scan thru all intervals
        foreach (array_keys($this->Config->get('snapshots')) as $snapshot_type)
        {
            //types must be stored as keys in array!
            $map[$snapshot_type] = [];

            foreach($this->snapshots[$path] as $snapshot)
            {
                // check which snapshot belongs to which type
                if(preg_match('/^'.$snapshot_type.'/', $snapshot))
                {
                    $map[$snapshot_type][]= $snapshot;
                }
            }
        }

        return $map;
    }

    /**
     * The regular expression of the snapshot
     *
     * @return string
     */
    function snapshot_regex()
    {
        //check if dir
        $hostname = str_replace('.', '\.', $this->Config->get('local.hostdir-name'));

        // zfs snapshots start with string incremental-..., 1-hourly.., etc. So we do not check that part.
        $regex = "$hostname\.[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.poppins$";

        return $regex;
    }
}