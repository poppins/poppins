<?php
/**
 * File application.class.inc.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class Application contains common functions used throughout the application.
 */
class Application
{
    // Cmd class
    public $Cmd;

    // Config class
    protected $Config;

    // Options class - options passed by getopt and ini file
    protected $Options;

    // Settings class - application specific settings
    protected $Settings;

    // Messages intended for output
    private $messages;
    // Array of messages with specific output color
    private $cmessages;

    // Errors
    private $errors = [];
    //Warnings
    private $warnings = [];

    /**
     * Application constructor.
     */
    function __construct()
    {
        #####################################
        # CONFIGURATION
        #####################################
        //Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // App specific settings
        $this->Settings = Settings::get_instance();
    }

    /**
     * Will take a string and add a color to it.
     *
     * @param $string
     * @param string $fgcolor Foreground color
     * @param bool $bgcolor Background color
     * @return string The colorized string
     */
    function colorize($string, $fgcolor = 'white', $bgcolor = false)
    {
        //colorize the string
        if ($this->Options->is_set('color'))
        {
            // foreground
            $fgcolors = ['black' => '0;30','dark_gray' => '1;30','blue' => '0;34','light_blue' => '1;34','green' => '0;32','light_green' => '1;32','cyan' => '0;36',
                         'light_cyan' => '1;36','red' => '0;31','light_red' => '1;31','purple' => '0;35','light_purple' => '1;35','brown' => '0;33','yellow' => '1;33',
                         'light_gray' => '0;37','white' => '1;37'];
            //background
            $bgcolors = ['black' => '40','red' => '41','green' => '42','yellow' => '43','blue' => '44','magenta' => '45','cyan' => '46','light_gray' => '47'];
            //return string
            $colored_string = '';
            // set foreground
            if (isset($fgcolors[$fgcolor]))
            {
                $colored_string .= "\033[" . $fgcolors[$fgcolor] . "m";
            }
            // set background
            if (isset($bgcolors[$bgcolor]))
            {
                $colored_string .= "\033[" . $bgcolors[$bgcolor] . "m";
            }
            $colored_string .=  $string . "\033[0m";
            return $colored_string;
        }
        else
        {
            return $string;
        }
    }

    /**
     * Quit the application unsuccessfully
     *
     * @param string $message The (error) message
     * @param string $error Type of error
     */
    function fail($message = '', $error = 'generic')
    {
        $this->errors []= $message;
        //compose message
        $output = [];
        $output []= "$message";
        $this->out(implode("\n", $output), 'error');
        //quit
        $this->quit($error);
    }

    /**
     * Initialise the class
     * Read and validate cli options
     * Read and validate ini file
     * Validate all ini directives
     * Configure all directories
     * Check package dependencies
     * Setup ssh connection as required
     * Check if directories are clean
     */
    function init()
    {
        #####################################
        # HELP
        #####################################
        $CLI_SHORT_OPTS = ["c:dhvt:"];
        $CLI_LONG_OPTS = ["version", "help", "color"];
        $options = getopt(implode('', $CLI_SHORT_OPTS), $CLI_LONG_OPTS);
        $this->Options->update($options);
        if (!count($this->Options->get()))
        {
            $content = file_get_contents(dirname(__FILE__).'/../documentation.txt');
            preg_match('/SYNOPSIS\n(.*?)\n/s', $content, $match);
            $this->abort("Usage: " . trim($match[1]));
        }
        elseif($this->Options->is_set('h') || $this->Options->is_set('help'))
        {
            print $this->Settings->get('appname').' '.$this->Settings->get('version')."\n\n";
            $content = file_get_contents(dirname(__FILE__).'/../documentation.txt');
            print "$content\n";
            exit();
        }
        elseif($this->Options->is_set('v') || $this->Options->is_set('version'))
        {
            print $this->Settings->get('appname').' version '.$this->Settings->get('version')."\n";
            $content = file_get_contents(dirname(__FILE__).'/../license.txt');

            $this->abort($content);
        }
        //check tag
        if ($this->Options->is_set('t'))
        {
            if (!$this->Options->get('t'))
            {
                $this->abort("Option -t {tag} may not be empty!");
            }
        }
        #####################################
        # START
        #####################################
        $this->out($this->Settings->get('appname').' v'.$this->Settings->get('version')." - SCRIPT STARTED " . date('Y-m-d H:i:s', $this->Settings->get('start_time')), 'title');
        $this->out('Environment', 'header');
        #####################################
        # LOCAL ENVIRONMENT
        #####################################
        // operating system
        $this->out('Check local operating system...');
        $OS = trim(shell_exec('uname'));
        if (!in_array($OS, ['Linux', 'SunOS']))
        {
            $this->abort("Local OS currently not supported!");
        }
        $this->out($OS, 'simple-indent');
         // PHP version
        $this->out('Check PHP version...');
        // full version e.g. 5.5.9-1ubuntu4.17
        $this->Settings->set('php.version.full', PHP_VERSION);
        // display version - debugging purposes
        $this->out($this->Settings->get('php.version.full'), 'simple-indent');
        // version id e.g. 505070
        $this->Settings->set('php.version.id', PHP_VERSION_ID);
        //check version < 5.6.1
        //  TODO implement deprecated - see parse_ini_file($configfile, 1, INI_SCANNER_TYPED);
        if($this->Settings->get('php.version.id') < 506010)
        {
            //$this->fail('PHP version 5.6.1 or higher required!');
        }
        #####################################
        # SETUP COMMANDS
        #####################################
        $Cmd = CmdFactory::create($OS);
        //load commands
        $this->Cmd = $Cmd;
        // hostname
        $this->out('Check hostname...');
        $hostname = $this->Cmd->exe('hostname');
        $this->Settings->set('local.hostname', $hostname);
        $this->out($hostname, 'simple-indent');
        $this->out();
        $this->out('OK!', 'simple-success');
        #####################################
        # LOAD OPTIONS FROM INI FILE
        #####################################
        $this->out("Parse ini file", 'header');
        //validate config file
        if (!$this->Options->is_set('c'))
        {
            $this->abort("Option -c {configfile} is required!");
        }
        // read configfile
        $configfile = $this->Options->get('c');
        if (!file_exists($configfile))
        {
            $this->abort("Config file not found!");
        }
        elseif (!preg_match('/^.+\.poppins\.ini$/', $configfile))
        {
            $this->abort("Wrong ini file format: {hostname}.poppins.ini!");
        }
        else
        {
            //check for illegal comments in ini file
            $lines = file($configfile);
            $i = 1;
            foreach($lines as $line)
            {
                if(preg_match('/^#/', $line))
                {
                    $this->fail("Error on line $i. Hash (#) found! Use semicolon for comments!");
                }
                $i++;
            }
            // read config
            $config = parse_ini_file($configfile, 1);
            // TODO PHP > 5.6
            // $config = parse_ini_file($configfile, 1, INI_SCANNER_TYPED);
            if(!$config)
            {
                $this->fail('Error parsing ini file!');
            }
            $this->Config->update($config);
            // check cli options of format --foo-bar
            $override_options= [];
            foreach($this->Config->get() as $k => $v)
            {
                foreach($v as $kk => $vv)
                {
                    $override_options[]= "$k-$kk::";
                }
            }
            //store override options
            $options = getopt(implode('', $CLI_SHORT_OPTS), $override_options);
            //allow a yes or no value in override
            foreach($options as $k => $v)
            {
                if(in_array($v, ['yes', 'true']))
                {
                    $options[$k] = '1';
                }
                elseif(in_array($v, ['no', 'false']))
                {
                    $options[$k] = '';
                }
            }
            $this->Options->update($options);
            //override configuration with cli options
            foreach($options as $k => $v)
            {
                if(in_array("$k::", $override_options))
                {
                    $p = explode('-', $k);
                    // section
                    $k1 = $p[0];
                    unset ($p[0]);
                    // directive
                    $k2 = implode('-', $p);
                    $this->Config->set([$k1, $k2], $v);
                }
            }
        }
        #####################################
        # CONFIGURATION CLEANUP
        #####################################
        //trim spaces
        $this->out("Check configuration syntax...");
        foreach ($this->Config->get() as $k => $v)
        {
            //do not validate if included or excluded directories
            if(in_array($k, ['included', 'excluded']))
            {
                //trim commas
                foreach ($v as $kk => $vv)
                {
                    $this->Config->set([$k, $kk], preg_replace('/\s?,\s?/', ',', $vv));
                }
            }
            else
            {
                //loop thru key/value pairs
                foreach ($v as $kk => $vv)
                {
                    //check for white space
                    $vv1 = str_replace(" ", "", $vv);
                    if($vv != $vv1)
                    {
                        $this->warn('Config values may not contain spaces. Value for '.$kk.' ['.$k.'] is trimmed!');
                        $this->Config->set([$k, $kk], $vv1);
                    }
                    //No trailing slashes
                    if (preg_match('/\/$/', $vv))
                    {
                        $this->fail("No trailing slashes allowed in config file! $kk = $vv...");
                    }
                }
            }
        }
        #####################################
        # ABORT IF NO ACTION NEEDED
        #####################################
        //check if there is anything to do
        if(!count($this->Config->get('included')) && !$this->Config->get('mysql.enabled'))
        {
            $this->fail("No directories configured for backup nor MySQL configured. Nothing to do...");
        }
        #####################################
        # VALIDATE INI FILE
        #####################################
        $json_file = dirname(__FILE__).'/../ini.json';
        if (!file_exists($json_file))
        {
            $this->fail('Cannot find required json file ' . $json_file);
        }
        $contents = file_get_contents($json_file);
        $json = json_decode($contents, true);
        if(!$json)
        {
            $this->fail('Cannot parse json file:"'.json_last_error_msg().'"!');
        }
        //iterate sections
        foreach($json['sections'] as $section)
        {
            if (!$this->Config->is_set($section['name']))
            {
                $this->fail('Section [' . $section['name'] . '] is not set!');
            }
            //add snapshots to validation
            if($section['name'] == 'snapshots')
            {
                foreach(array_keys($this->Config->get('snapshots')) as $k)
                {
                    $section['directives'][] = ['name' => $k, 'validate'=> ['integer'=>'error']];
                }
            }
            // iterate all directives
            if(isset($section['directives']) && is_array($section['directives']))
            {
                //validate directives
                foreach ($section['directives'] as $directive)
                {
                    //skip validation if dependency is not met
                    if(isset($directive['depends']) && !$this->Config->get($directive['depends']))
                    {
                        continue;
                    }
                    //initiate message
                    $message = '';
                    if($this->Config->is_set([$section['name'], $directive['name']]))
                    {
                        // set value
                        $value = $this->Config->get([$section['name'], $directive['name']]);
                        #####################################
                        # ALLOWED CHARACTERS
                        #####################################
                        if (!Validator::contains_allowed_characters($value))
                        {
                            //check bad characters - #&;`|*?~<>^()[]{}$\, \x0A and \xFF. ' and " are escaped
                            $escaped = escapeshellcmd($value);
                            if ($value != $escaped)
                            {
                                //allow tilde in home paths!
                                $allow_homepath = false;
                                $allowed_homepaths = ['homepath', 'absolutepath|homepath', 'mysqlpaths'];
                                foreach($allowed_homepaths as $h)
                                {
                                    if(isset($directive['validate'][$h]))
                                    {
                                        $allow_homepath = true;
                                        break;
                                    }
                                }
                                if ($allow_homepath)
                                {
                                    $paths = explode(',', $value);
                                    foreach ($paths as $p)
                                    {
                                        $p = trim($p);
                                        if (preg_match('/^~/', $p))
                                        {
                                            if (!Validator::is_relative_home_path($p))
                                            {
                                                $this->fail("Directive " . $directive['name'] . " [" . $section['name'] . "] is not a home path!");
                                            }
                                            $p = preg_replace('/\~/', '', $p);
                                        }
                                        //check characters
                                        if (!Validator::contains_allowed_characters($p))
                                        {
                                            $this->fail("Illegal character found in MySQL configdir path '$value' in directive " . $directive['name'] . " [" . $section['name'] . "]!");
                                        }
                                    }
                                }
                                else
                                {
                                    $this->fail("Illegal character found in string '$value' in directive " . $directive['name'] . " [" . $section['name'] . "]!");
                                }
                            }
                        }
                        #####################################
                        # ALLOWED
                        #####################################
                        if (isset($directive['validate']['allowed']))
                        {
                            $allowed = $directive['validate']['allowed'];
                            $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an allowed value. Use values "'.implode('/', $allowed).'"!';
                            if (!in_array($value, $allowed))
                            {
                                if ($directive['validate']['allowed'] == 'warning')
                                {
                                    $this->warn($message);
                                }
                                else
                                {
                                    $this->fail($message);
                                }
                            }
                        }
                        #####################################
                        # BOOLEAN
                        #####################################
                        if (isset($directive['validate']['boolean']))
                        {
                            $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not a valid boolean. Use values yes/no without quotes!';
                            if (in_array($value, ['yes', 'true']))
                            {
                                $this->Config->set([$section['name'], $directive['name']], '1');
                                $this->warn($message);
                            }
                            elseif (in_array($value, ['no', '0', 'false']))
                            {
                                $this->Config->set([$section['name'], $directive['name']], '');
                                $this->warn($message);
                            }
                            elseif (!in_array($value, ['', '1']))
                            {
                                $this->fail($message);
                            }
                        }
                        #####################################
                        # INTEGER
                        #####################################
                        if (isset($directive['validate']['integer']))
                        {
                            if (!preg_match("/^[0-9]+$/", $value))
                            {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an integer!';
                                if ($directive['validate']['integer'] == 'warning')
                                {
                                    $this->warn($message);
                                }
                                else
                                {
                                    $this->fail($message);
                                }
                            }
                        }
                        #####################################
                        # ABSOLUTE OR RELATIVE HOME PATH
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['absolutepath|homepath']))
                        {
                            if (!Validator::is_absolute_path($value) && (!Validator::is_relative_home_path($value)))
                            {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an absolute/home path!';
                                if ($directive['validate']['absolutepath|homepath'] == 'warning')
                                {
                                    $this->warn($message);
                                }
                                else
                                {
                                    $this->fail($message);
                                }
                            }
                        }
                        #####################################
                        # 1 ABSOLUTE PATH
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['absolutepath']))
                        {
                            if (!Validator::is_absolute_path($value))
                            {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an absolute path!';
                                if ($directive['validate']['absolutepath'] == 'warning')
                                {
                                    $this->warn($message);
                                }
                                else
                                {
                                    $this->fail($message);
                                }
                            }
                        }
                        #####################################
                        # 1 ABSOLUTE PATH OR EMPTY
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['absolutepath?']))
                        {
                            if (!empty($value) && !Validator::is_absolute_path($value))
                            {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an absolute path!';
                                if ($directive['validate']['absolutepath?'] == 'warning')
                                {
                                    $this->warn($message);
                                }
                                else
                                {
                                    $this->fail($message);
                                }
                            }
                        }
                        #####################################
                        # MULTIPLE MYSQL PATHS
                        #####################################
                        if (isset($directive['validate']['mysqlpaths']))
                        {
                            //set to home if empty
                            if(empty($value))
                            {
                                $this->Config->set([$section['name'], $directive['name']], $directive['default']);
                            }
                            else
                            {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] contains an illegal path!';
                                $paths = explode(',', $value);
                                if(!count($paths))
                                {
                                    $paths = [$value];
                                }
                                foreach($paths as $path)
                                {
                                    if($path != '~' && !Validator::is_absolute_path($path) && (!Validator::is_relative_home_path($path)))
                                    {
                                        if ($directive['validate']['mysqlpaths'] == 'warning')
                                        {
                                            $this->warn($message);
                                        }
                                        else
                                        {
                                            $this->fail($message);
                                        }
                                    }
                                }
                            }
                        }
                    }
                    #####################################
                    # DEFAULTS
                    #####################################
                    else
                    {
                        $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not set!';
                        // check if default value
                        if (isset($directive['default']))
                        {
                            $this->Config->set([$section['name'], $directive['name']], $directive['default']);
                            $this->warn($message.' Using default value ('.$directive['default'].').');
                        }
                        else
                        {
                            $this->fail($message);
                        }
                    }
                }
            }
        }
        #####################################
        # VALIDATE INCLUDED/EXCLUDED
        #####################################
        //validate spaces in keys of included section
        foreach ($this->Config->get('included') as $k => $v)
        {
            $k1 = str_replace(' ', '\ ', stripslashes($k));
            if ($k != $k1)
            {
                $this->fail("You must escape white space in [included] section!");
            }
        }
        //validate spaces in values of included/excluded section
        foreach(['included', 'excluded'] as $section)
        {
            foreach ($this->Config->get($section) as $k => $v)
            {
                $v1 = str_replace(' ', '\ ', stripslashes($v));
                if ($v != $v1)
                {
                    $this->fail("You must escape white space in [$section] section!");
                }
            }
        }
        //validate included/excluded syntax
        $included = array_keys($this->Config->get('included'));
        $excluded = array_keys($this->Config->get('excluded'));
        foreach($excluded as $e)
        {
            if(!in_array($e, $included))
            {
                $this->fail("Unknown excluded directory index \"$e\"!");
            }
        }
        //validate snapshot config
        $this->out("Check snapshot config...");
        foreach($this->Config->get('snapshots') as $k => $v)
        {
            //check syntax of key
            if($k != 'incremental' && !preg_match('/^[0-9]+-(' .  implode("|", $this->Settings->get('intervals')).')$/', $k))
            {
                $this->fail("Error in snapshot configuration, $k not supported!");
            }
        }
        #####################################
        # SET LOG DIR
        #####################################
        //check log dir early so we can log stuff
        $this->out('Check logdir...');
        $logdir = $this->Config->get('local.logdir');
        //validate dir, create if required
        if($logdir)
        {
            if (!file_exists($logdir))
            {
                $this->out('Create logdir  ' . $logdir . '...');
                $this->Cmd->exe("mkdir -p " . $logdir);
                if ($this->Cmd->is_error())
                {
                    $this->fail('Cannot create log dir ' . $logdir);
                }
            }
        }
        #####################################
        # SET SSH CONNECTION
        #####################################
        // only applicable for ssh
        if($this->Config->get('remote.ssh'))
        {
            $this->out('Check remote parameters...');
            //validate user
            $remote_user  = ($this->Config->get('remote.user'))? $this->Config->get('remote.user'):$this->Cmd->exe('whoami');
            $this->Config->set('remote.user', $remote_user);
            //check remote host
            if ($this->Config->get('remote.host') == '')
            {
                $this->fail("Remote host is not configured!!");
            }
            //first ssh attempt
            $this->out('Check ssh connection...');
            //obviously try ssh at least once :)
            $attempts = 1;
            //retry attempts on connection fail
            if ($this->Config->get('remote.retry-count'))
            {
                $attempts += (integer)$this->Config->get('remote.retry-count');
            }
            //allow for a timeout
            $timeout = 0;
            if ($this->Config->get('remote.retry-timeout'))
            {
                $timeout += (integer)$this->Config->get('remote.retry-timeout');
            }
            $i = 1;
            $success = false;
            while ($i <= $attempts)
            {
                $this->Cmd->exe("'echo OK'", true);
                if ($this->Cmd->is_error())
                {
                    $this->warn("SSH connection failed!");
                    if ($i != $attempts)
                    {
                        $this->out("Will retry ssh attempt " . ($i + 1) . " of $attempts in $timeout second(s)...\n");
                        sleep($timeout);
                    }
                    $i++;
                }
                else
                {
                    $success = true;
                    break;
                }
            }
            //check if successful
            $user = $this->Config->get('remote.user');
            $host = $this->Config->get('remote.host');
            if (!$success)
            {
                $this->fail("SSH login attempt $user@$host failed! \nGenerate a key with ssh-keygen and ssh-copy-id to set up a passwordless ssh connection?");
            }
        }
        //get remote os
        $this->Config->set('remote.os', $this->Cmd->exe("uname", true));
        //get distro
        // try /etc/*release
        $output = $this->Cmd->exe("'cat /etc/*release'", true);
        if ($this->Cmd->is_error())
        {
            $output = $this->Cmd->exe("'lsb_release -a'", true);
            if ($this->Cmd->is_error())
            {
                 $this->fail('Cannot discover remote distro!');
            }
        }
        foreach (['Ubuntu', 'Debian', 'SunOS', 'OpenIndiana', 'Red Hat', 'CentOS', 'Fedora', 'Manjaro', 'Arch'] as $d)
        {
            if (preg_match("/$d/i", $output))
            {
                $this->Config->set('remote.distro', $d);
                break;
            }
        }
        //check pre backup script
        if(!$this->Config->is_set('remote.pre-backup-script'))
        {
            $this->warn('Directive pre-backup-script [remote] is not configured!');
        }
        //check if set and if so, validate onfail action
        elseif($this->Config->get('remote.pre-backup-script') != '')
        {
            //check if path is set correctly
            if (!Validator::is_absolute_path($this->Config->get('remote.pre-backup-script')))
            {
                $this->fail("pre-backup-script must be an absolute path!");
            }
            //check if action is set correctly
            if(!in_array($this->Config->get('remote.pre-backup-onfail'), ['abort', 'continue']))
            {
                $this->fail('Wrong value for "pre-backup-onfail". Use "abort" or "continue"!');
            }
        }
        #####################################
        # CHECK DEPENDENCIES
        #####################################
        $this->out('Check dependencies...');
        $remote_distro = $this->Config->get('remote.distro');
        $dependencies = [];
        //Debian - Ubuntu
        if(in_array($remote_distro, ['Debian', 'Ubuntu']))
        {
//            $dependencies['remote']['aptitude'] = 'aptitude --version';
        }
        //Red Hat - Fedora
        if(in_array($remote_distro, ['Red Hat', 'CentOS', 'Fedora']))
        {
            //yum is nice though rpm will suffice, no hard dependency needed
            //$dependencies['remote']['yum-utils'] = 'yumdb --version';
        }
        //Arch - Manjaro
        if(in_array($remote_distro, ['Arch', 'Manjaro']))
        {
            $dependencies['remote']['pacman'] = 'pacman --version';
        }
        $dependencies['remote']['rsync'] = 'rsync --version';
        //local
        $dependencies['local']['gzip'] = 'gzip --version';
        $dependencies['local']['rsync'] = 'rsync --version';
        $dependencies['local']['grep'] = '{GREP} --version';
        //iterate packages
        foreach ($dependencies as $host => $packages)
        {
            foreach ($packages as $package => $command)
            {
                //check if installed
                $remote = ($host == 'remote')? true:false;
                $this->Cmd->exe($command, $remote);
                if ($this->Cmd->is_error())
                {
                    $this->fail("Package $package installed on $host machine?");
                }
            }
        }
        #####################################
        # SET ROOT DIR
        #####################################
        $this->out('Check rootdir...');
        $rootdir = $this->Config->get('local.rootdir');
        $snapshots_backend = $this->Config->get('local.snapshot-backend');
        //root dir must exist!
        if (!file_exists($rootdir))
        {
            $this->fail("Root dir '" . $rootdir . "' does not exist!");
        }
        //check filesystem
        else
        {
            $this->out('Check root dir filesystem type...');
            $filesystem_type = $this->Cmd->exe("df -T $rootdir | tail -1 | tr -s ' ' | cut -d' ' -f2");
            $this->out($filesystem_type, 'simple-indent');
            $allowed_fs_types = ['ext2', 'ext3', 'ext4', 'btrfs', 'zfs', 'xfs', 'ufs', 'jfs', 'nfs', 'gfs', 'ocfs'];
            if (!in_array($filesystem_type, $allowed_fs_types))
            {
                $this->fail('Filesystem type of root dir not supported! Supported: '.implode('/', $allowed_fs_types));
            }
        }
        //check filesystem config
        $this->out('Check filesystem config...');
        $supported_snapshot_backends = ['default', 'zfs', 'btrfs'];
        if(!in_array($snapshots_backend, $supported_snapshot_backends))
        {
            $this->fail('Local filesystem not supported! Supported: '.implode(",", $supported_snapshot_backends));
        }
        // validate filesystem
        switch($snapshots_backend)
        {
            case 'zfs':
            case 'btrfs':
                if($filesystem_type != $snapshots_backend)
                {
                    $this->fail('Rootdir is not a '.$snapshots_backend.' filesystem!');
                }
                break;
            default:
        }
        //validate root dir and create if required
        switch ($snapshots_backend)
        {
            //if using zfs, we want a mount point
            case 'zfs':
                //check if mount point
                $rootdir_check = $this->Cmd->exe("zfs get -H -o value mountpoint ".$rootdir);
                if($rootdir_check != $rootdir)
                {
                    $this->fail("No zfs mount point " . $rootdir . " found!");
                }
                //validate if dataset name and mountpoint are the same
                $zfs_info = $this->Cmd->exe("zfs list | grep '".$rootdir."$'");
                $a = explode(' ', $zfs_info);
                if('/'.reset($a) != end($a))
                {
                    $this->fail('zfs name and mountpoint do not match!');
                }
                break;
            default:
                if (!file_exists($rootdir))
                {
                    $this->Cmd->exe("mkdir -p " . $rootdir);
                    if ($this->Cmd->is_error())
                    {
                        $this->fail("Could not create directory:  " . $rootdir . "!");
                    }
                }
        }
        #####################################
        # SET HOST DIR
        #####################################
        $this->out('Check host...');
        if($this->Config->get('local.hostdir-name'))
        {
            $dirname = $this->Config->get('local.hostdir-name');
        }
        elseif($this->Config->get('remote.host'))
        {
            $dirname = $this->Config->get('remote.host');
        }
        else
        {
            $this->fail('No hostdir-name [local] configured!');
        }
        $this->Config->set('local.hostdir-name', $dirname);
        //check if no slashes
        if (preg_match('/^\//', $this->Config->get('local.hostdir-name')))
        {
            $this->fail("hostname may not contain slashes!");
        }
        $this->Config->set('local.hostdir', $this->Config->get('local.rootdir') . '/' . $this->Config->get('local.hostdir-name'));
        //validate host dir and create if required
        switch ($this->Config->get('local.snapshot-backend'))
        {
            //if using zfs, we want to check if a filesystem is in place, otherwise, create it
            case 'zfs':
                $hostdir_check = $this->Cmd->exe("zfs get -H -o value mountpoint " . $this->Config->get('local.hostdir'));
                if ($hostdir_check != $this->Config->get('local.hostdir'))
                {
                    if ($this->Config->get('local.hostdir-create'))
                    {
                        $zfs_fs = preg_replace('/^\//', '', $this->Config->get('local.hostdir'));
                        $this->out("zfs filesystem " . $zfs_fs . " does not exist, creating zfs filesystem..");
                        $this->Cmd->exe("zfs create " . $zfs_fs);
                        if ($this->Cmd->is_error())
                        {
                            $this->fail("Could not create zfs filesystem:  " . $zfs_fs . "!");
                        }
                    }
                    else
                    {
                        $this->fail("Directory " . $this->Config->get('local.hostdir') . " does not exist! Directive not set to create it (no)..");
                    }
                }
                //validate if dataset name and mountpoint are the same
                $zfs_info = $this->Cmd->exe("zfs list | grep '".$this->Config->get('local.hostdir')."$'");
                $a = explode(' ', $zfs_info);
                if ('/' . reset($a) != end($a))
                {
                    $this->fail('zfs name and mountpoint do not match!');
                }
                break;
            default:
                //check if dir exists
                if (!file_exists($this->Config->get('local.hostdir')))
                {
                    if ($this->Config->get('local.hostdir-create'))
                    {
                        $this->out("Directory " . $this->Config->get('local.hostdir') . " does not exist, creating it..");
                        $this->Cmd->exe("mkdir -p " . $this->Config->get('local.hostdir'));
                        if ($this->Cmd->is_error())
                        {
                            $this->fail("Could not create directory:  " . $this->Config->get('local.hostdir') . "!");
                        }
                    }
                    else
                    {
                        $this->fail("Directory " . $this->Config->get('local.hostdir') . " does not exist! Directive not set to create it..");
                    }
                }
                break;
        }
        #####################################
        # SET RSYNC DIR
        #####################################
        //set syncdir
        switch($this->Config->get('local.snapshot-backend'))
        {
            case 'zfs':
            case 'btrfs':
                $rsyncdir = 'rsync.'.strtolower($this->Config->get('local.snapshot-backend'));
                break;
            default:
                $rsyncdir = 'rsync.dir';
        }
        $this->Config->set('local.rsyncdir',  $this->Config->get('local.hostdir').'/'.$rsyncdir);
        #####################################
        # CHECK IF RSYNC DIR IS CLEAN
        #####################################
        $dir = $this->Config->get('local.rsyncdir').'/files';
        if(file_exists($dir))
        {
            $allowed = array_map('stripslashes', array_values($this->Config->get('included')));
            $diff = Validator::contains_allowed_files($dir, $allowed);
            if (count($diff))
            {
                foreach ($diff as $file => $type)
                {
                    $this->warn("Directory $dir not clean, unknown $type '$file'..");
                }
            }
        }
        #####################################
        # CHECK IF META DIR IS CLEAN
        #####################################
        $filebase = strtolower($this->Config->get('local.hostdir-name') . '.' . $this->Settings->get('appname'));
        $this->Settings->set('meta.filebase', $filebase);
        //check if meta dir is clean
        $dir = $this->Config->get('local.rsyncdir').'/meta';
        if(file_exists($dir))
        {
            $allowed = [];
            $directives = ['remote-disk-layout' => 'disk-layout.txt', 'remote-package-list' => 'packages.txt'];
            foreach ($directives as $directive => $file)
            {
                if ($this->Config->get(['meta', $directive]))
                {
                    $allowed [] = $filebase . '.' . $file;
                }
            }
            $diff = Validator::contains_allowed_files($dir, $allowed);
            if (count($diff))
            {
                foreach ($diff as $file => $type)
                {
                    $this->warn("Directory $dir not clean, unknown $type '$file'..");
                }
            }
        }
        #####################################
        # CHECK IF MYSQL DIR IS CLEAN
        #####################################
        //check if mysql dir is clean
        if(!$this->Config->get('mysql.enabled'))
        {
            $dir = $this->Config->get('local.rsyncdir').'/mysql';
            if(file_exists($dir))
            {
                $allowed = [];
                $diff = Validator::contains_allowed_files($dir, $allowed);
                if (count($diff))
                {
                    foreach ($diff as $file => $type)
                    {
                        $this->warn("Directory $dir not clean, found $type '$file' while MySQL is disabled..");
                    }
                }
            }
        }
        #####################################
        # CHECK IF ARCHIVE DIR IS CLEAN
        #####################################
        //check if archive dir is clean
        $dir = $this->Config->get('local.hostdir') . '/archive';
        if(file_exists($dir))
        {
            $allowed = array_keys($this->Config->get('snapshots'));
            $exact_match = ($this->Config->get('local.snapshot-backend') == 'zfs')? false:true;
            $diff = Validator::contains_allowed_files($dir, $allowed, $exact_match);
            if (count($diff))
            {
                foreach ($diff as $file => $type)
                {
                    $this->warn("Directory $dir not clean, unknown $type '$file'..");
                }
            }
        }
        $this->out();
        $this->out('OK!', 'simple-success');
        ######################################
        # DUMP ALL CONFIG
        #####################################
        $hostname = ($this->Config->get('remote.ssh'))? '@'.$this->Config->get('remote.host'):'(LOCAL)';
        $this->out('List configuration '.$hostname, 'header');
        $output = [];
        foreach ($this->Config->get() as $k => $v)
        {
            if (is_array($v))
            {
                ksort($v);
                $output [] = "\n[$k]";
                foreach ($v as $kk => $vv)
                {
                    $vv = ($vv) ? $vv : '""';
                    $output [] = sprintf("%s = %s", $kk, $vv);
                }
            }
            else
            {
                $v = ($v) ? $v : '""';
                $output [] = sprintf("%s = %s", $k, $v);
            }
        }
        $this->out(trim(implode("\n", $output)));
        $this->out();
        $this->out('OK!', 'simple-success');
        #####################################
        # CREATE LOCK FILE
        #####################################
        # check for lock
        if (file_exists($this->Config->get('local.hostdir') . "/LOCK"))
        {
            $this->fail("LOCK file " . $this->Config->get('local.hostdir') . "/LOCK exists!", 'LOCKED');
        }
        else
        {
            $this->out();
            $this->out('Create LOCK file...');
            $this->Cmd->exe("touch " . $this->Config->get('local.hostdir') . "/LOCK");
        }
    }

    /**
     * Add a message to messages array
     * Add a message to colored messages array if required
     *
     * @param string $message The message
     * @param bool $fgcolor Foreground color
     * @param bool $bgcolor Background color
     */
    function log($message = '', $fgcolor = false, $bgcolor = false)
    {
        $this->messages [] = $message;
        //output color
        if($fgcolor) $this->cmessages [count($this->messages) - 1] = [$fgcolor, $bgcolor];
    }

    /**
     * Add style to the message
     *
     * @param string $message The message
     * @param string $type The type of style
     */
    function out($message = '', $type = 'default')
    {
        $content = [];
        $fgcolor = false;
        $bgcolor = false;
        switch ($type)
        {
            case 'error':
                $fgcolor = 'light_red';
                $l1 = '$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$ FATAL ERROR $$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$';
                $l2 = '$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$';
                $content [] = '';
                $content [] = $l1;
                $content [] = wordwrap($message, 80);
                $content [] = $l2;
                $content [] = '';
                break;
            case 'final-error':
                $fgcolor = 'light_red';
                $content [] = '';
                $content [] = $message;
                $content [] = '';
                break;
            case 'final-success':
                $fgcolor = 'green';
                $content [] = '';
                $content [] = $message;
                $content [] = '';
                break;
            case 'header':
                $l = "-----------------------------------------------------------------------------------------";
                $content [] = '';
                $content [] = $l;
                $content [] = strtoupper($message);
                $content [] = $l;
                break;
            case 'indent':
                $fgcolor = 'cyan';
                $content [] = "-----------> " . $message;
                break;
            case 'indent-error':
                $fgcolor = 'light_red';
                $content [] = "-----------> " . $message;
                break;
            case 'indent-warning':
                $fgcolor = 'brown';
                $content [] = "-----------> " . $message;
                break;
            case 'simple-error':
                $fgcolor = 'light_red';
                $content [] = $message;
                break;
            case 'simple-indent':
                $fgcolor = 'cyan';
                $content [] = ' '.$message;
                break;
            case 'simple-info':
                $fgcolor = 'cyan';
                $content [] = $message;
                break;
            case 'simple-warning':
                $fgcolor = 'brown';
                $content [] = $message;
                break;
            case 'simple-success':
                $fgcolor = 'green';
                $content [] = $message;
                break;
            case 'title':
                $l = "%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%";
                $content [] = $l;
                $content [] = $message;
                $content [] = $l;
                break;
            case 'warning':
                $fgcolor = 'brown';
                $l1 = '|||||||||||||||||||||||||||||||||||| WARNING |||||||||||||||||||||||||||||||||||||||||||||';
                $l2 = '||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||';
                $content [] = '';
                $content [] = $l1;
                $content [] = wordwrap($message, 85);
                $content [] = $l2;
                $content [] = '';
                break;
            case 'default':
                $content [] = $message;
                break;
        }
        $message = implode("\n", $content);
        //log to file
        $this->log($message, $fgcolor, $bgcolor);
    }

    /**
     * Abort the application.
     *
     * @param string $message The (error) message.
     */
    function abort($message = '')
    {
        if($message)
        {
            $message .= "\n";
        }
	fwrite(STDERR, $message);
        die(1);
    }

    /**
     * Quit the application.
     *
     * @param bool $error Set to true if unsuccessful
     */
    function quit($error = false)
    {
        //debug output
        if($this->Options->is_set('d'))
        {
            $this->out("List commands", 'header');
            $output = [];
            foreach($this->Cmd->commands as $c)
            {
                $output []= $c;
            }
            $output []= "";
            $this->out(implode("\n", $output));
        }
        //title
        $this->out('Summary', 'header');
        //report warnings
        $warnings = count($this->warnings);
        //output all warnings
        if($warnings)
        {
           $this->out("WARNINGS (".$warnings.")", 'simple-warning');
            foreach($this->warnings as $w)
            {
                $this->out($w, 'indent-warning');
            }
            $this->out();
        }
        //report errors
        $errors = count($this->errors);
        if($errors)
        {
           $this->out("ERRORS (".$errors.")", 'simple-error');
            foreach($this->errors as $e)
            {
                $this->out($e, 'indent-error');
            }
            $this->out();
        }
        //log message
        if(!$error)
        {
            $this->out("SCRIPT RAN SUCCESSFULLY!", 'final-success');
        }
        else
        {
            $this->out("SCRIPT FAILED!", 'final-error');

        }
        //add tag to log file
        if ($this->Options->is_set('t') && $this->Options->get('t'))
        {
            $tag = $this->Options->get('t');

        }
        else
        {
            $tag = '(untagged)';
        }
        $this->log("Run tagged as: $tag");
        //time script
        $lapse = date('U') - $this->Settings->get('start_time');
        $lapse = gmdate('H:i:s', $lapse);
        $this->log("Script time: $lapse (HH:MM:SS)");
        $this->log();
        //final header
        $this->out($this->Settings->get('appname').' v'.$this->Settings->get('version'). " - SCRIPT ENDED " . date('Y-m-d H:i:s'), 'title');
        #####################################
        # OUTPUT
        #####################################
        //colorize output
        $content = [];
        $i = 0;
        foreach($this->messages as $m)
        {
            if(@isset($this->cmessages[$i]))
            {
                $content []= $this->colorize($m, $this->cmessages[$i][0], $this->cmessages[$i][1]);
            }
            else
            {
                $content []= $m;
            }
            $i++;
        }
        #####################################
        # CLEANUP
        #####################################
        //remove LOCK file if exists
        if ($error != 'LOCKED' && file_exists(@$this->Config->get('local.hostdir') . "/LOCK"))
        {
            $content [] = "Remove LOCK file...";
            $this->Cmd->exe('{RM} ' . $this->Config->get('local.hostdir') . "/LOCK");
        }
        #####################################
        # WRITE LOG TO FILES
        #####################################
        //write to log
        if (is_dir($this->Config->get('local.logdir')))
        {
            if ($this->Config->get('local.hostdir-name'))
            {
                //output
                if($error)
                {
                    $result = 'error';
                }
                else
                {
                    $result = (count($this->warnings))? 'warning':'success';
                }

                $hostdirname = ($this->Config->get('local.hostdir-name'))? $this->Config->get('local.hostdir-name'):$this->Config->get('remote.host');
                $logfile_host = $this->Config->get('local.logdir') . '/' . $hostdirname . '.' . date('Y-m-d_His', $this->Settings->get('start_time')) . '.poppins.' . $result. '.log';
                $logfile_app = $this->Config->get('local.logdir') . '/poppins.log';
                $content [] = 'Create logfile for host ' . $logfile_host . '...';
                //create file
                $this->Cmd->exe("touch " . $logfile_host);

                if ($this->Cmd->is_error())
                {
                    $content []= 'WARNING! Cannot write to host logfile. Cannot create log file!';
                }
                else
                {
                    $success = file_put_contents($logfile_host, implode($this->messages, "\n")."\n");
                    if (!$success)
                    {
                        $content []= 'WARNING! Cannot write to host logfile. Write protected?';
                    }
                    //write to application log
                    $this->Cmd->exe("touch " . $logfile_app);
                    if ($this->Cmd->is_error())
                    {
                        $content [] = 'WARNING! Cannot write to application logfile. Cannot create log file!';
                    }
                    $m = [];
                    $m['timestamp'] = date('Y-m-d H:i:s');
                    $m['host'] = $hostdirname;
                    $m['result'] = strtoupper($result);
                    $m['lapse'] = $lapse;
                    $m['logfile'] = $logfile_host;
                    //compress host logfile?
                    if($this->Config->get('log.compress'))
                    {
                        $content [] = 'Compress log file...';
                        $this->Cmd->exe("gzip " . $logfile_host);
                        //append suffix in log
                        $m['logfile'] .= '.gz';
                    }
                    $m['version'] = $this->Settings->get('version');
                    // add tag to entry
                    if($this->Options->is_set('t') && $this->Options->get('t'))
                    {
                        $m['tag'] = $this->Options->get('t');
                    }
                    foreach($m as $k => $v)
                    {
                        $m[$k] = '"'.$v.'"';
                    }
                    $message = implode(' ', array_values($m))."\n";
                    $content [] = 'Add "'.$result.'" to logfile ' . $logfile_app . '...';
                    $success = file_put_contents($logfile_app, $message, FILE_APPEND | LOCK_EX);
                    if (!$success)
                    {
                        $content []= 'WARNING! Cannot write to application logfile. Write protected?';
                    }
                }
            }
            else
            {
                $content []= 'WARNING! Cannot write to host logfile. Hostdir not set!';
            }
        }
        else
        {
            $content []= 'WARNING! Cannot write to logfile. Log directory not created!';
        }
        //be polite
        $content [] = "Bye...";
        //last newline
        $content [] = "";
        //output
        $this->abort(implode("\n", $content));
    }

    /**
     * Quit the application successfully
     */
    function succeed()
    {
        #####################################
        # DISK USAGE
        #####################################
        //list disk usage
        if ($this->Config->get('log.local-disk-usage'))
        {
            $this->out('Disk Usage', 'header');
            // disk usage dirs
            $dirs = [];
            // $dirs ['rsync directory (total size)'] []= $this->Config->get('local.rsyncdir');
            $dirs ['/files'] []= $this->Config->get('local.rsyncdir').'/files';
            if($this->Config->get('mysql.enabled'))
            {
                // total MySQL
                $path = $this->Config->get('local.rsyncdir').'/mysql';
                $dirs ['/mysql'] []= $path;
                // mysqldumps seperately
                $scan = scandir($path);
                foreach($scan as $s)
                {
                    if(!in_array($s, ['.', '..']))
                    {
                        $path1 = $path.'/'.$s;
                        if(is_dir($path1))
                        {
                            $scan1 = scandir($path1);
                            foreach($scan1 as $s1)
                            {
                                if(!in_array($s1, ['.', '..'])) $dirs['mysqldumps'] []= $path1.'/'.$s1;
                            }
                        }
                    }
                }
            }
            // iterate all directories
            $i = 1;
            foreach($dirs as $section => $subdirs)
            {
                $this->out("Disk usage of $section...");
                foreach($subdirs as $subdir)
                {
                    if (file_exists($subdir))
                    {
                        $du = $this->Cmd->exe("du -sh $subdir");
                        $this->out("$du");
                    }
                    else
                    {
                        $this->warn('Cannot determine disk usage of ' . $subdir);
                    }
                }
                // space
                if($i < count($dirs))
                {
                    $this->out();
                }
                $i++;
            }
        }
        $this->quit();
    }

    /**
     * Add a warning
     *
     * @param $message The message
     */
    function warn($message)
    {
        $this->warnings []= $message;
        $this->out($message, $type = 'warning');
    }

}
