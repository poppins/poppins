<?php
/**
 * Class DirectoryStructure contains functions used to create required local directories
 */
require_once dirname(__FILE__).'/DirectoryStructure.php';

class DefaultDirectoryStructure extends DirectoryStructure
{
    function setup_host_dir()
    {
        parent::setup_host_dir();

        //check if dir exists
        if (!file_exists($this->Config->get('local.hostdir')))
        {
            if ($this->Config->get('local.hostdir-create'))
            {
                $this->App->out("Directory " . $this->Config->get('local.hostdir') . " does not exist, creating it..");
                $this->Cmd->exe("mkdir " . $this->Config->get('local.hostdir'));
                if ($this->Cmd->is_error())
                {
                    $this->App->fail("Could not create directory:  " . $this->Config->get('local.hostdir') . "!");
                }
            }
            else
            {
                $this->App->fail("Directory " . $this->Config->get('local.hostdir') . " does not exist!");
            }
        }
    }

    function setup_rsync_dir()
    {
        $rsync_dir_name = 'rsync.dir';
        $this->rsync_dir = $this->Config->get('local.hostdir').'/'.$rsync_dir_name;

        // check if exists
        if (!file_exists($this->rsync_dir))
        {
            $this->App->out("Create sync dir $this->rsync_dir...");
            $this->Cmd->exe("mkdir " . $this->rsync_dir);

            if ($this->Cmd->is_error())
            {
                $this->App->fail('Failed to create rsync dir!');
            }
        }

        $this->Config->set('local.rsync_dir',  $this->rsync_dir);
    }

}