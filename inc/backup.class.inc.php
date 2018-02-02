<?php
/**
 * File backup.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class Backup contains functions used to backup files and directories,
 * metadata and mysql databses.
 */
class Backup
{
    //Application class
    public $App;

    // Config class
    protected $Config;

    // Options class - options passed by getopt and ini file
    protected $Options;

    // Settings class - application specific settings
    protected $Settings;

    //rsyncdir
    protected $rsyncdir;

    /**
     * Backup constructor.
     *
     * @param $App Application class
     */
    function __construct($App)
    {
        $this->App = $App;

        $this->Cmd = $App->Cmd;
        #####################################
        # CONFIGURATION
        #####################################
        //Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // App specific settings
        $this->Settings = Settings::get_instance();

        $this->rsyncdir = $this->Config->get('local.rsyncdir');
    }

    /**
     * Initialise the class
     */
    function init()
    {
        //create dirs
        $this->prepare();
        //remote system info
        $this->meta();
        //mysql
        if ($this->Config->get('mysql.enabled'))
        {
            $this->mysql();
        }
        //rsync
        $this->rsync();
    }

    /**
     * Lookup rsync status message
     *
     * @param $rsync_code Rsync error code
     * @return string The message
     */
    function get_rsync_status($rsync_code)
    {
        //list error codes
        $codes = [];
        $codes[0] = 'Success';
        $codes[1] = 'Syntax or usage error';
        $codes[2] = 'Protocol incompatibility';
        $codes[3] = 'Errors selecting input/output files, dirs';
        $codes[4] = 'Requested  action not supported: an attempt was made to manipulate 64-bit files on a platform that cannot support them; or an option was specified that is supported by the client and not by the server.';
        $codes[5] = 'Error starting client-server protocol';
        $codes[6] = 'Daemon unable to append to log-file';
        $codes[10] = 'Error in socket I/O';
        $codes[11] = 'Error in file I/O';
        $codes[12] = 'Error in rsync protocol data stream';
        $codes[13] = 'Errors with program diagnostics';
        $codes[14] = 'Error in IPC code';
        $codes[20] = 'Received SIGUSR1 or SIGINT';
        $codes[21] = 'Some error returned by waitpid()';
        $codes[22] = 'Error allocating core memory buffers';
        $codes[23] = 'Partial transfer due to error';
        $codes[24] = 'Partial transfer due to vanished source files';
        $codes[25] = 'The --max-delete limit stopped deletions';
        $codes[30] = 'Timeout in data send/receive';
        $codes[35] = 'Timeout waiting for daemon connection';
        //message
        $message = '';
        if (is_int($rsync_code) && isset($codes[$rsync_code]))
        {
            $message = $codes[$rsync_code];
        }
        return $message;
    }

    /**
     * Backup remote mysql databases
     */
    function mysql()
    {
        #####################################
        # MYSQL BACKUPS
        #####################################
        $this->App->out('Mysql backups', 'header');
        // output
        $output_type = ($this->Config->is_set('mysql.output-type'))? $this->Config->get('mysql.output-type'):'databases';
        //check config directories
        $config_dirs = [];
        if ($this->Config->get('mysql.configdirs'))
        {
            $config_dirs = explode(',', $this->Config->get('mysql.configdirs'));
        }
        //assume home dir
        else
        {
            $config_dirs [] = '~';
        }
        //cache config files
        $cached = [];
        //iterate dirs
        foreach ($config_dirs as $config_dir)
        {
            // test
            $output = false;
            //check if allowed
            $this->Cmd->exe("'cd $config_dir' 2>&1", true);
            if ($this->Cmd->is_error())
            {
                $this->App->warn('Cannot access remote mysql configdir ' . $config_dir . '...');
            }
            else
            {
                $output = $this->Cmd->exe("'cd $config_dir;ls .my.cnf* 2>/dev/null'", true);
            }
            //check output
            if ($output)
            {
                $configfiles = explode("\n", $output);
            }
            else
            {
                $configfiles = [];
                $this->App->warn('Cannot find mysql config files in remote dir ' . $config_dir . '...');
                continue;
            }
            if (count($configfiles))
            {
                //iterate config files
                foreach ($configfiles as $config_file)
                {
                    // executables
                    $mysqldump_executable = "mysqldump --defaults-file=$config_dir/$config_file";
                    $mysql_executable = "mysql --defaults-file=$config_dir/$config_file";
                    //instance
                    $instance = preg_replace('/^.+my\.cnf(\.)?/', '', $config_file);
                    $instance = ($instance) ? $instance : 'default';
                    //ignore if file is the same
                    $contents = $this->Cmd->exe("'cd $config_dir;cat .my.cnf*'", true);
                    if (in_array($contents, $cached))
                    {
                        $this->App->notice("Found duplicate mysql config file $config_dir/$config_file...");
                        continue;
                    }
                    else
                    {
                        $cached [] = $contents;
                    }
                    #####################################
                    # SETUP MYSQLDUMP DIR
                    #####################################
                    $this->App->out("Backup databases from $config_dir/$config_file");
                    $mysqldump_dir = "$this->rsyncdir/mysql/$instance";
                    // check if dir exists
                    if (!is_dir($mysqldump_dir))
                    {
                        $this->App->out("Create directory $mysqldump_dir...");
                        $this->Cmd->exe("mkdir -p $mysqldump_dir");
                    }
                    // empty the dir
                    else
                    {
                        $this->App->out("Empty directory $mysqldump_dir...");
                        // ignore error in case of empty dir: || true
                        $this->Cmd->exe("rm -f $mysqldump_dir/*");
                    }
                    // create subdirs
//                    $this->App->out("Create subdirectories in $mysqldump_dir...");
//                    $this->Cmd->exe("mkdir -p $mysqldump_dir/sql");
//                    if ($output_type == 'csv')
//                    {
//                        $this->Cmd->exe("mkdir -p $mysqldump_dir/csv");
//                    }
                    #####################################
                    # CHECK INSTALLED DATABASES
                    #####################################
                    $_databases = [];
                    $dbs_found = $this->Cmd->exe("'$mysql_executable --skip-column-names -e \"show databases\" | grep -v \"^information_schema$\"'", true);
                    $_databases['found'] = explode("\n", $dbs_found);
                    // check if any databases found
                    if (!count($_databases['found']))
                    {
                        $this->fail('No databases found to backup!');
                    }
                    #####################################
                    # INCLUDED/EXCLUDED DATABASES
                    #####################################
                    // skip if not configured
                    if ($this->Config->is_set('mysql.included-databases') || $this->Config->is_set('mysql.excluded-databases'))
                    {
                        //check which databases to include or exclude
                        $db_exists_check = true;
                        foreach (['included', 'excluded'] as $include_type)
                        {
                            if ($this->Config->is_set('mysql.' . $include_type . '-databases'))
                            {
                                $_databases['config'][$include_type] = explode(',', $this->Config->get('mysql.' . $include_type . '-databases'));
                            }
                            else
                            {
                                $_databases['config'][$include_type] = [];
                            }
                        }
                        // check if these databases exist
                        foreach (['included', 'excluded'] as $include_type)
                        {
                            $_databases[$include_type] = [];
                            foreach ($_databases['config'][$include_type] as $pattern)
                            {
                                $matched = [];
                                // no regex is used
                                if(!preg_match('/^{.+}$/', $pattern))
                                {
                                    if(in_array($pattern, $_databases['found']))
                                    {
                                        $matched []= $pattern;
                                    }
                                }
                                // regex is used
                                else
                                {
                                    $pattern = trim($pattern, '{}');
                                    d($pattern);
                                    $matched = preg_grep('/'.$pattern.'/', $_databases['found']);
                                    d($matched);
                                }
                                if (!count($matched))
                                {
                                    $message = ucfirst($include_type) . ' database pattern "' . $pattern . '" not found!';
                                    // warn first, fail later
                                    $this->App->warn($message);
                                    $db_exists_check = false;
                                }
                                else
                                {
                                    foreach($matched as $m)
                                    {
                                        array_push($_databases[$include_type], $m);
                                    }
                                }
                            }
                        }
                        // one or more databases does not exist
                        if (!$db_exists_check)
                        {
                            $this->App->fail('Include/exclude databases not properly configured!');
                        }
                    }
                    #####################################
                    # DATABASES TO BACKUP
                    #####################################
                    // get databases
                    $_databases['backup'] = (count($_databases['included']))? $_databases['included']:$_databases['found'];
                    // check the blacklist
                    if (count($_databases['excluded']))
                    {
                        // remove the excluded databases from the array
                        foreach ($_databases['excluded'] as $exclude)
                        {
                            if (($key = array_search($exclude, $_databases['backup'])) !== false)
                            {
                                unset($_databases['backup'][$key]);
                            }
                        }
                    }
                    dd($_databases);
                    #####################################
                    # VALIDATE TABLES CONFIG
                    #####################################
                    $_tables = [];
                    // no need to do these checks if not set
                    if ($this->Config->is_set('mysql.included-tables') || $this->Config->is_set('mysql.excluded-tables') || in_array($this->Config->get('mysql.outputfile-type'), ['csv','tables']))
                    {
                        // search tables
                        foreach ($_databases['backup'] as $db)
                        {
                            $tbls_found = $this->Cmd->exe("'$mysql_executable --skip-column-names -e \"use $db; show tables;\"'", true);
                            $_tables['found'][$db] = explode("\n", $tbls_found);
                        }
                        #####################################
                        # INCLUDED/EXCLUDED CONFIG
                        #####################################
                        // get included/excluded tables
                        foreach (['included', 'excluded'] as $include_type)
                        {
                            $_tables[$include_type] = [];
                            if ($this->Config->is_set('mysql.' . $include_type . '-tables'))
                            {
                                $_tables['config'][$include_type] = explode(',', $this->Config->get('mysql.' . $include_type . '-tables'));
                            }
                            else
                            {
                                $_tables['config'][$include_type] = [];
                            }
                        }
                        // check the config
                        $table_exists_check = true;
                        foreach (['included', 'excluded'] as $include_type)
                        {
                            $_tables[$include_type] = [];
                            foreach ($_tables['config'][$include_type] as $config)
                            {
                                $matched = [];
                                // match the db
                                preg_match('/^([^\.]+)/', $config, $match);
                                $db = $match[0];
                                // check if database is included
                                if (!in_array($db, $_databases['backup']))
                                {
                                    $this->App->fail('Cannot process '.$include_type.' table pattern: "'.$config.'". Database not included.');
                                }
                                // no regex is used
                                if(!preg_match('/^.+\.{.+}$/', $config))
                                {
                                    // match the table
                                    preg_match('/([^\.]+)$/', $config, $match);
                                    $pattern = $match[0];
                                    if(in_array($pattern, $_tables['found'][$db]))
                                    {
                                        $matched []= $pattern;
                                    }
                                }
                                // regex is used
                                else
                                {
                                    // match the table pattern
                                    preg_match('/{(.+)}$/', $config, $match);
                                    $pattern = trim($match[0], '{}');
                                    $matched = preg_grep('/'.$pattern.'/i', $_tables['found'][$db]);
                                }
                                // nothing found
                                if (!count($matched))
                                {
                                    $message = ucfirst($include_type) . ' table pattern "' . $config . '" not found!';
                                    // warn first, fail later
                                    $this->App->warn($message);
                                    $table_exists_check = false;
                                }
                                else
                                {
                                    //create array if not exists
                                    if(!in_array($db, array_keys($_tables[$include_type])))
                                    {
                                        $_tables[$include_type][$db] = [];
                                    }
                                    foreach($matched as $m)
                                    {
                                        array_push($_tables[$include_type][$db], $m);
                                    }
                                }
                            }

                        }
                        // one or more databases does not exist
                        if (!$table_exists_check)
                        {
                            $this->App->fail('Include/exclude tables not properly configured!');
                        }
                        d($_tables);
                        #####################################
                        # CHECK IF TABLES EXIST
                        #####################################
                        foreach (['included', 'excluded'] as $include_type)
                        {
                            foreach($_tables[$include_type] as $db => $tables)
                            {
                                foreach($tables as $table)
                                {
                                    //check if the table exists
                                    $this->Cmd->exe("'$mysql_executable -e \"select 1 from $db.$table\"'", true);
                                    if ($this->Cmd->is_error())
                                    {
                                        $this->App->fail(ucfirst($include_type) . ' table ' . $db . '.' . $table . ' not found!');
                                    }
                                    $_tables[$include_type][$db] [] = $table;
                                }
                            }
                        }
                        #####################################
                        # TABLES TO BACKUP
                        #####################################
                        foreach ($_databases['backup'] as $db)
                        {
                            // get included tables
                            if(isset($_tables['included'][$db]))
                            {
                                $_tables['backup'][$db] = $_tables['included'][$db];
                            }
                            else
                            {
                                $_tables['backup'][$db] = $_tables['found'][$db];
                            }
                            // remove the excluded tables from the array
                            if(@is_array($_tables['excluded'][$db]))
                            {
                                foreach ($_tables['excluded'][$db] as $exclude)
                                {
                                    if (($key = array_search($exclude, $_tables['backup'][$db])) !== false)
                                    {
                                        unset($_tables['backup'][$db][$key]);
                                    }
                                }
                            }
                            #####################################
                            # TABLES TO IGNORE
                            #####################################
                            $_tables['ignore'][$db] = array_diff($_tables['found'][$db], $_tables['backup'][$db]);
                        }

                    }
                    //default ignored tables
                    if(!$this->Config->is_set('mysql.excluded-tables'))
                    {
                        $_tables['ignore']['mysql'] = 'mysql.event';
                    }
                    #####################################
                    # MYSQLDUMP OPTIONS
                    #####################################
                    //TODO mysqldump options - hidden feature at the moment
                    //could look like this:
//                  ; mysqldump options - use at your own risk
//                  ; --databases is not allowed
//                  ; default is: --routines --single-transaction --quick
//                  mysqldump-options = '--routines,--single-transaction,--quick'
                    if ($this->Config->is_set('mysql.mysqldump-options'))
                    {
                        $mysqldump_options = $this->Config->get('mysql.mysqldump-options');
                        //basic validation
                        $mysqldump_option_blacklist = ['--databases', '-B', '--host', '-h', '--tables', '--help', '-?', '--version', '-V', '--all-databases', '-A', '--tables'];
                        $mysqldump_options_tmp = explode(',', $mysqldump_options);
                        foreach ($mysqldump_options_tmp as $o)
                        {
                            // check if it looks like an option - too many possibilities to really do a regex here
                            if(!preg_match('/^--?/', $o))
                            {
                                $this->App->fail('Illegal mysqldump option! Option: '.$o);
                            }
                            elseif(in_array($o, $mysqldump_option_blacklist))
                            {
                                $this->App->fail('Cannot use mysqldump option! Option: '.$o);
                            }
                        }
                        // glue the pieces back together
                        $mysqldump_options = implode(' ', $mysqldump_options_tmp);
                    }
                    else
                    {
                        $mysqldump_options = '--routines --single-transaction --quick';
                    }
                    #####################################
                    # IGNORE TABLES
                    #####################################
                    $tables_ignore = [];
                    foreach ($_databases['backup'] as $db)
                    {
                        $tables_ignore['database'][$db] = '';
                        //are there any specific tables to ignore?
                        if (is_array($_tables['ignore'][$db]) && count($_tables['ignore'][$db]))
                        {
                            $tables_excluded_tmp = [];
                            foreach ($_tables['ignore'][$db] as $table)
                            {
                                $tables_excluded_tmp [] = '--ignore-table=' . $db . '.' . $table;
                            }
                            $tables_ignore['database'][$db] = implode(' ', $tables_excluded_tmp);
                        }
                    }
                    #####################################
                    # CONSTRUCT MYSQLDUMP COMMAND
                    #####################################
                    $mysqldump_commands = [];
                    // compress the dumps
                    $compress = ($this->Config->is_set('mysql.compress'))? $this->Config->get('mysql.compress'):true;
                    $gzip_pipe = ($compress)? '| gzip':'';
                    $gzip_extension = ($compress)? '.gz':'';
                    // build mysqldump commands depending on output
                    switch ($output_type)
                    {
                        case 'complete':
                            // ignore tables
                            $ignore_tmp = [];
                            foreach($tables_ignore['database'] as  $k => $v)
                            {
                                $ignore_tmp []= $v;
                            }
                            $ignore = implode(' ', $ignore_tmp);
                            $dbs = implode(' ', $_databases['backup']);
                            $mysqldump_commands['all databases'] = "'$mysqldump_executable $ignore $mysqldump_options --databases $dbs' $gzip_pipe > $mysqldump_dir/complete.sql$gzip_extension";
                            break;
                        case 'databases':
                            // create statement
                            if ($this->Config->get('mysql.create-database'))
                            {
                                $mysqldump_options .= ' --databases';
                            }
                            // loop throuh the databases
                            foreach($_databases['backup'] as $db)
                            {
                                $ignore = $tables_ignore['database'][$db];
                                $mysqldump_commands[$db] = "'$mysqldump_executable $ignore $mysqldump_options $db' $gzip_pipe > $mysqldump_dir/$db.sql$gzip_extension";
                            }
                            break;
                        case 'tables':
                        case 'csv':
                            foreach($_databases['backup'] as $db)
                            {
                                foreach($_tables['backup'][$db] as $table)
                                {
                                    if($output_type == 'tables')
                                    {
                                        $mysqldump_commands[$db.'.'.$table] = "'$mysqldump_executable $mysqldump_options $db $table' $gzip_pipe > $mysqldump_dir/$db.$table.sql$gzip_extension";
                                    }
                                    elseif ($output_type == 'csv')
                                    {
                                        $mysqldump_commands[$db.'.'.$table.' (sql)'] = "'$mysqldump_executable $mysqldump_options $db $table' $gzip_pipe > $mysqldump_dir/$db.$table.sql$gzip_extension";
                                        $mysqldump_commands[$db.'.'.$table.' (csv)'] = "'$mysql_executable -B -e \"SELECT * FROM $db.$table\"' $gzip_pipe > $mysqldump_dir/$db.$table.csv$gzip_extension";
                                        print "'$mysql_executable -B -e \"SELECT * FROM $db.$table\"' $gzip_pipe > $mysqldump_dir/$db.$table.csv$gzip_extension";
                                        //TODO mysqldump on remote machine
                                        // privilege issues - mysqldump: Got error: 1290: The MySQL server is running with the --secure-file-priv option so it cannot execute this statement when executing 'SELECT INTO OUTFILE'
//                                        ; seperator for csv
//                                        ; use ';', ',' or '\t'
//                                        fields-terminated-by = ';'
//
//                                        ; fields enclosure for csv
//                                        ; use '\"' or ''
//                                        fields-enclosed-by = '\"'
//
//                                        ; csv end of lines
//                                        ; values may be '\r', '\n' or '\r\n'
//                                        lines-terminated-by = '\n'
                                        // set defaults
//                                        $csv_options = [
//                                            'fields-terminated-by' => '\t',
//                                            'fields-enclosed-by' => '',
//                                            'lines-terminated-by' => '\n'
//                                        ];
//                                        $csv_options_tmp = [];
//                                        foreach ($csv_options as $k => $v)
//                                        {
//                                            // set to whatever is configured
//                                            if ($this->Config->is_set('mysql.'.$k))
//                                            {
//                                                $csv_options_tmp [] = '--'.$k.'='.$this->Config->get('mysql.'.$k).'';
//                                            }
//                                            else
//                                            {
//                                                $csv_options_tmp []= '--'.$k.'='.$v.'';
//                                            }
//                                            $csv_options = implode(' ', $csv_options_tmp);
//                                        }
//                                        $mysqldump_commands []= "'$mysqld_executable -t -T/tmp/poppins.tmp/ $db $table $csv_options'";

                                    }
                                }
                            }
                            break;
                    }
                    #####################################
                    # EXECUTE MYSQLDUMP COMMAND
                    #####################################
                    foreach ($mysqldump_commands as $key => $cmd)
                    {
//                        echo "\n".$cmd."\n\n";
//                        continue;
                        $this->Cmd->exe($cmd, true);
                        if (!$this->Cmd->is_error())
                        {
                            $this->App->out("$key... OK.", 'indent');
                        }
                        else
                        {
                            $this->App->fail("mysql backup failed! Command: " . $cmd);
                        }
                    }
                }
            }
        }
    }

    /**
     * Execute remote jobs/scripts before/after backups
     * @param string $type Pre or Post backup job
     */
    function jobs($type = 'pre')
    {
        #####################################
        # PRE BACKUP JOBS
        #####################################
        // do our thing on the remote end.
        $this->App->out($type.' backup script', 'header');
        //check if jobs
        if ($this->Config->get('remote.'.$type.'-backup-script'))
        {
            $this->App->out('Remote script configured, validating...');
            $script = $this->Config->get('remote.'.$type.'-backup-script');
            //test if the script exists
            $this->Cmd->exe("'test -x $script'", true);
            if ($this->Cmd->is_error())
            {
                $message = 'Remote '.$type.'-backup script is not an executable script!';
                if ($this->Config->get('remote.'.$type.'-backup-onfail') == 'abort')
                {
                    $this->App->fail($message);
                }
                else
                {
                    $this->App->warn($message);
                }
            }
            //run remote command
            $this->App->out('Running remote script...');
            $output = $this->Cmd->exe("'$script 2>&1'", true);
            $this->App->out('Output:');
            $this->App->out();
            $this->App->out($output);
            $this->App->out();
            if ($this->Cmd->is_error())
            {
                $message = 'Remote '.$type.'-backup script did not run successfully!';
                if ($this->Config->get('remote.'.$type.'-backup-onfail') == 'abort')
                {
                    $this->App->fail($message);
                }
                else
                {
                    $this->App->warn($message);
                }
            }
            else
            {
                $this->App->out('Remote job done... (' . date('Y-m-d H:i:s') . ')');
                $this->App->out();
                $this->App->out("OK!", 'simple-success');
            }
        }
        else
        {
            $this->App->out('No '.$type.' backup script defined...');
        }
    }

    /**
     * Gather metadata about remote installation such as disk and packages
     */
    function meta()
    {
        //variables
        $filebase = $this->Settings->get('meta.filebase');
        $this->App->out('Metadata', 'header');
        //disk layout
        if ($this->Config->get('meta.remote-disk-layout'))
        {
            $this->App->out('Gather information about disk layout...');
            // remote disk layout and packages
            if ($this->Config->get('remote.os') == "Linux")
            {
                $this->Cmd->exe("'( df -hT 2>&1; vgs 2>&1; pvs 2>&1; lvs 2>&1; blkid 2>&1; lsblk -fi 2>&1; for disk in $(ls /dev/sd[a-z] /dev/cciss/* 2>/dev/null) ; do fdisk -l \$disk 2>&1; done )' > $this->rsyncdir/meta/" . $filebase . ".disk-layout.txt", true);

                if ($this->Cmd->is_error())
                {
                    $this->App->warn('Failed to gather information about disk layout!');
                }
                else
                {
                    $this->App->out("Write to file $this->rsyncdir/meta/" . $filebase . ".disk-layout.txt...");
                    $this->App->out();
                    $this->App->out("OK!", 'simple-success');
                }
            }
        }
        else
        {
            $this->App->out('Skip information about disk layout...');
        }
        $this->App->out();
        //packages
        if ($this->Config->get('meta.remote-package-list'))
        {
            $this->App->out('Gather information about packages...');
            $packages = [];
            switch ($this->Config->get('remote.distro'))
            {
                case 'Debian':
                case 'Ubuntu':
                    $packages['aptitude --version'] = "aptitude search \"~i !~M\" -F \"%p\" --disable-columns | sort -u";
                    $packages['dpkg --version'] = "dpkg --get-selections";
                    break;
                case 'Red Hat':
                case 'CentOS':
                case 'Fedora':
                    $packages['yumdb --version'] =  "yumdb search reason user | sort | grep -v \"reason = user\" | sed '/^$/d'";
                    $packages['rpm --version'] =  "rpm -qa";
                    break;
                case 'Arch':
                case 'Manjaro':
                    $packages['pacman --version'] =  "pacman -Qet";
                    break;
                default:
                    $this->App->out('Remote OS not supported.');
                    break;
            }
            //retrieve packge list
            $c = count($packages);
            $i = 1;
            foreach ($packages as $validation => $execution)
            {
                $this->Cmd->exe("'$validation' 2>&1", true);
                if ($this->Cmd->is_error())
                {
                    //no more commands to execute, fail
                    if($i == $c)
                    {
                        $this->App->fail('Failed to retrieve package list! Remote package manager(s) not installed?');
                    }
                }
                else
                {
                    $this->Cmd->exe("'$execution' > $this->rsyncdir/meta/" . $filebase . ".packages.txt", true);
                    //possibly sed, grep or sort not installed?
                    if ($this->Cmd->is_error())
                    {
                        //no more commands to execute, fail
                        if ($i == $c)
                        {
                            $this->App->fail('Failed to retrieve package list! Cannot execute command!');
                        }
                        else
                        {
                            //warn???
                            continue;
                        }
                    }
                    //success, break!
                    else
                    {
                        $arr = explode(' ',trim($validation));
                        $pkg_mngr = $arr[0];
                        $this->App->out("Using the $pkg_mngr package manager. Write to file $this->rsyncdir/meta/" . $filebase . ".packages.txt...");
                        $this->App->out();
                        $this->App->out("OK!", 'simple-success');
                        break;
                    }
                }
                $i++;
            }
        }
        else
        {
            $this->App->out('Skip information about packages...');
        }
    }

    /**
     * Prepare backups
     */
    function prepare()
    {
        #####################################
        # PRE BACKUP JOB
        #####################################
        $this->jobs('pre');
        #####################################
        # SYNC DIR
        #####################################
        if (!file_exists($this->rsyncdir))
        {
            $this->App->out("Create sync dir $this->rsyncdir...");
            $this->create_syncdir();
        }
        #####################################
        # OTHER DIRS
        #####################################
        $a = ['meta', 'files'];
        if ($this->Config->get('mysql.enabled'))
        {
            $a [] = 'mysql';
        }
        foreach ($a as $aa)
        {
            if (!file_exists($this->rsyncdir . '/' . $aa))
            {
                $this->App->out("Create $aa dir $this->rsyncdir/$aa...");
                $this->Cmd->exe("mkdir -p $this->rsyncdir/$aa");
            }
        }
    }

    /**
     * Rsync remote files and directories
     */
    function rsync()
    {
        //rsync backups
        $this->App->out('Sync data', 'header');
        #####################################
        # CHECK FOR MOUNTED FILESYSTEMS
        #####################################
        $this->App->out('Check mounted remote filesystems...');
        if (!$this->Config->get('rsync.cross-filesystem-boundaries'))
        {
            $mounts = [];
            $output = $this->Cmd->exe("'cat /proc/mounts'", true);
            $output = explode("\n", $output);
            foreach($output as $o)
            {
                if (preg_match('/^\//', $o))
                {
                    $p = explode(' ', $o);
                    $mounts []= $p[1];
                }
            }
            $this->App->out(implode(", ", $mounts), 'simple-indent');
            $excluded = $this->Config->get('excluded');
            $excluded_paths = [];
            foreach ($excluded as $k => $v)
            {
                $exploded = explode(',', $v);
                foreach($exploded as $e)
                {
                    $excluded_paths[$k][]=  rtrim($k, '/').'/'.rtrim($e, '/');
                }
            }
            $included = array_keys($this->Config->get('included'));
            // check if mounts are in backup paths
            foreach($mounts as $m)
            {
                # initiate crossed_path
                $crossed_path = false;
                # the mount is not specified in included
                if(!in_array($m, $included))
                {
                    foreach ($included as $i)
                    {
                        # check if mount is found in included dirs
                        if (0 === strpos($m, $i))
                        {
                            # check if mount is excluded
                            if (!array_key_exists ($i, $excluded_paths))
                            {
                                $crossed_path = $i;
                            }
                            else
                            {
                                # check all excluded paths
                                foreach($excluded_paths[$i] as $p)
                                {
                                    # compare the paths with mounts
                                    if(0 === strpos($m, $p))
                                    {
                                        $crossed_path = false;
                                        break;
                                    }
                                    else
                                    {
                                        $crossed_path = $i;
                                    }
                                }
                            }
                        }
                    }
                    # crossed filesystem found
                    if($crossed_path)
                    {
                        $this->App->warn('Mount point "'.$m.'" found in path "'.$crossed_path.'". Will not cross filesystem boundaries!');
                    }
                }
            }
        }
        #####################################
        # RSYNC OPTIONS
        #####################################
        $this->App->out('Run rsync commands...');
        $this->App->out();
        //options
        $o = [];
        $o [] = "--delete-excluded --delete --numeric-ids";

        //ssh
        if ($this->Config->get('remote.ssh'))
        {
            $ssh = $this->Cmd->parse('{SSH}');
            $o [] = '-e "' . $ssh . ' -o TCPKeepAlive=yes -o ServerAliveInterval=30"';
        }

        // general options
        if ($this->Config->get('rsync.verbose'))
        {
            $o [] = "-v";
        }
        if (!$this->Config->get('rsync.cross-filesystem-boundaries'))
        {
            $o [] = "-x";
        }
        if ($this->Config->get('rsync.hardlinks'))
        {
            $o [] = "-H";
        }
        if (in_array((integer) $this->Config->get('rsync.compresslevel'), range(1, 9)))
        {
            $o [] = "-z --compress-level=" . $this->Config->get('rsync.compresslevel');
        }
        // rewrite as little blocks as possible. do not set this for default!
        if (in_array($this->Config->get('local.snapshot-backend'), ['zfs', 'btrfs']))
        {
            $o [] = "--inplace";
        }
        $rsync_options = implode(' ', $o);
        #####################################
        # RSYNC DIRECTORIES
        #####################################
        //errors
        $FATAL_ERRORS = [];
        foreach ($this->Config->get('included') as $source => $target)
        {
            //exclude dirs
            $excluded = [];
            if ($this->Config->get(['excluded', $source]))
            {
                $exludedirs = explode(',', $this->Config->get(['excluded', $source]));

                foreach ($exludedirs as $d)
                {
                    $excluded [] = "--exclude=$d";
                }
            }
            //excluded files
            $excluded = implode(' ', $excluded);
            //output command
            $this->App->out("rsync '$source' @ " . date('Y-m-d H:i:s') . "...", 'indent');
            if (!is_dir("$this->rsyncdir/files/$target"))
            {
                $this->App->out("Create target dir $this->rsyncdir/files/$target...");
                $this->Cmd->exe("mkdir -p $this->rsyncdir/files/$target");
            }
            //check trailing slash
            $sourcedir = (preg_match('/\/$/', $source)) ? $source : "$source/";
            $targetdir = "$this->rsyncdir/files/$target/";
            //slashes are protected by -s option in rsync
            $sourcedir = stripslashes($sourcedir);
            $targetdir = stripslashes($targetdir);
            $remote_connection = ($this->Config->get('remote.ssh'))? $this->Config->get('remote.user') . "@" . $this->Config->get('remote.host') .':':'';
            $cmd = "rsync $rsync_options -as $excluded " .$remote_connection. "\"$sourcedir\" '$targetdir' 2>&1";
            $this->App->out($cmd);
            //obviously try rsync at least once :)
            $attempts = 1;
            //retry attempts on rsync fail
            if ($this->Config->get('rsync.retry-count'))
            {
                $attempts += (integer) $this->Config->get('rsync.retry-count');
            }
            //retry timeout between attempts
            $timeout = 0;
            if ($this->Config->get('rsync.retry-timeout'))
            {
                $timeout += (integer) $this->Config->get('rsync.retry-timeout');
            }
            $i = 1;
            $success = false;
            while ($i <= $attempts)
            {
                $output = $this->Cmd->exe("$cmd");
                $this->App->out($output);
                //WARNINGS - allow some rsync errors to occur
                if (in_array($this->Cmd->exit_status, [24]))
                {
                    $message = $this->get_rsync_status($this->Cmd->exit_status);
                    $message = (empty($message)) ? '' : ': "' . $message . '".';
                    $this->App->notice("Rsync of $sourcedir directory exited with a non-zero status! Non fatal, will continue. Exit status: " . $this->Cmd->exit_status . $message);
                    $success = true;
                    break;
                }
                //ERRORS
                elseif ($this->Cmd->exit_status != 0)
                {
                    $message = $this->get_rsync_status($this->Cmd->exit_status);
                    $message = (empty($message)) ? '' : ': "' . $message . '".';
                    $this->App->warn("Rsync of $sourcedir directory attempt $i/$attempts exited with a non-zero status! Fatal, will abort. Exit status " . $this->Cmd->exit_status . $message);
                    $message = [];
                    if ($i != $attempts)
                    {
                        $message [] = "Will retry rsync attempt " . ($i + 1) . " of $attempts in $timeout second(s)...\n";
                        sleep($timeout);
                    }
                    $this->App->out(implode(' ', $message));
                    $i++;
                }
                //SUCCESS
                else
                {
                    $this->App->out("");
                    $success = true;
                    break;
                }
            }
            //check if successful
            if (!$success)
            {
                $message = $this->get_rsync_status($this->Cmd->exit_status);
                $message = (empty($message)) ? '' : ': "' . $message . '".';
                $output = "Rsync of $sourcedir directory failed! Aborting! Exit status " . $this->Cmd->exit_status . $message;
                $this->App->warn($output);
                $FATAL_ERRORS [] = $output;
            }
        }
        //check fatal error
        if(count($FATAL_ERRORS))
        {
            // even if the backup job failed, execute the post-script!
            if($this->Config->get('remote.backup-onfail') == 'abort')
            {
                // do not run post-backup script
                $this->App->warn("Will not run post-backup script!");
            }
            else
            {
                // run post-backup scripts
                $this->jobs('post');
            }
            $this->App->fail('One or more rsync jobs have failed!', 'simple-error');
        }
        else
        {
            $this->App->out("OK!", 'simple-success');
            // run post-backup scripts
            $this->jobs('post');
        }
    }

}

/**
 * Class BtrfsBackup based on btrfs filesystem (btrfs snapshots)
 */
class BtrfsBackup extends Backup
{

    /**
     * Create the syncdir
     */
    function create_syncdir()
    {
        $this->Cmd->exe("btrfs subvolume create " . $this->rsyncdir);
    }

}

/**
 * Class DefaultBackup based on default filesystem (hardlink rotation)
 */
class DefaultBackup extends Backup
{

    /**
     * Create the syncdir
     */
    function create_syncdir()
    {
        $this->Cmd->exe("mkdir -p " . $this->rsyncdir);
    }

}

/**
 * Class ZfsBackup based on zfs filesystem (zfs snapshots)
 */
class ZfsBackup extends Backup
{

    /**
     * Create the syncdir
     */
    function create_syncdir()
    {
        $rsyncdir = preg_replace('/^\//', '', $this->rsyncdir);
        $this->Cmd->exe("zfs create " . $rsyncdir);
    }

}
