<?php
/**
 * File Application.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */

/**
 * Class Application contains common functions used throughout the application.
 *
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

    //Notices
    private $notices = [];

    /**
     * Application constructor.
     */
    public function __construct()
    {
        #####################################
        # CONFIGURATION
        #####################################
        // Config from ini file
        $this->Config = Config::get_instance();

        // Command line options
        $this->Options = Options::get_instance();

        // Session specific settings
        $this->Session = Session::get_instance();
    }

    /**
     * Abort the execution of the application. No further output or
     * logs will be written. The application quits on stdout with an
     * error code 1 as default.
     *
     * @param string $message The (error) message.
     * @param integer $error_code The (error) code.
     */
    public function abort($message = '', $error_code = 1)
    {
        // add a newline (cosmetic)
        if ($message) {
            $message .= "\n";
        }

        // redirect output to out/error
        switch ($error_code) {
            case 0:
                fwrite(STDOUT, $message);
                break;
            default:
                fwrite(STDERR, $message);
                break;
        }

        // end the application
        exit($error_code);
    }

    /**
     * Will take a string and add a color to it.
     *
     * @param string $string The string to colorize
     * @param string $fgcolor Foreground color
     * @param bool $bgcolor Background color
     * @return string The colorized string
     */
    public function colorize($string, $fgcolor = 'white', $bgcolor = false)
    {
        //colorize the string
        if ($this->Options->is_set('color')) {
            // foreground
            $fgcolors = ['black' => '0;30', 'dark_gray' => '1;30', 'blue' => '0;34', 'light_blue' => '1;34', 'green' => '0;32', 'light_green' => '1;32', 'cyan' => '0;36',
                'light_cyan' => '1;36', 'red' => '0;31', 'light_red' => '1;31', 'purple' => '0;35', 'light_purple' => '1;35', 'brown' => '0;33', 'yellow' => '1;33',
                'light_gray' => '0;37', 'white' => '1;37'];

            //background
            $bgcolors = ['black' => '40', 'red' => '41', 'green' => '42', 'yellow' => '43', 'blue' => '44', 'magenta' => '45', 'cyan' => '46', 'light_gray' => '47'];

            //return string
            $colored_string = '';

            // set foreground
            if (isset($fgcolors[$fgcolor])) {
                $colored_string .= "\033[" . $fgcolors[$fgcolor] . "m";
            }

            // set background
            if (isset($bgcolors[$bgcolor])) {
                $colored_string .= "\033[" . $bgcolors[$bgcolor] . "m";
            }

            $colored_string .= $string . "\033[0m";

            return $colored_string;
        } else {
            return $string;
        }
    }

    /**
     * The application fails for some reason.
     * This function captures the error and type and will invoke
     * the final function in which te error will be logged.
     *
     * @param string $message The (error) message
     * @param string $error Type of error
     */
    public function fail($message = '', $error = 'generic')
    {
        // add the message to the errors array
        $this->errors[] = $message;

        // compose message
        $output = [];
        $output[] = "$message";
        $this->out(implode("\n", $output), 'error');

        // quit the applications
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
    public function init()
    {
        #####################################
        # CLI OPTIONS
        #####################################
        // short options
        $cli_short_options = ["c:dhnvt:"];

        // long options
        $cli_long_options = ["version", "help", "color"];

        // consolidate options
        $cli_options = getopt(implode('', $cli_short_options), $cli_long_options);
        $this->Options->update($cli_options);

        // file paths
        $usage_file_path = dirname(__FILE__) . '/../docs/USAGE';
        $license_file_path = dirname(__FILE__) . '/../docs/LICENSE';

        // if no options are supplied, show documentation
        if (!count($this->Options->get())) {
            $usage = file_get_contents($usage_file_path);
            preg_match('/SYNOPSIS\n(.*?)\n/s', $usage, $match);
            $content = "Usage: " . trim($match[1]);

            // display usage
            $this->abort($content);
        }
        // -h show help
        elseif ($this->Options->is_set('h') || $this->Options->is_set('help')) {
            $content = [];
            $content[] = $this->Session->get('appname') . ' ' . $this->Session->get('version');
            $content[] = '';

            // get documentation
            $documentation = file_get_contents($usage_file_path);
            $content[] = trim($documentation);

            // display documentation
            $this->abort(implode("\n", $content), 0);
        }
        // -v show version
        elseif ($this->Options->is_set('v') || $this->Options->is_set('version')) {
            $content = [];
            $content[] = $this->Session->get('appname') . ' version ' . $this->Session->get('version');
            $content[] = '';

            // get license
            $license = file_get_contents($license_file_path);
            // get latest year, e.g. Copyright (C) 2019 Free Software Foundation
            $license = preg_replace('/[0-9]{4} Free Software Foundation/', date("Y") . ' Free Software Foundation', $license);
            $content[] = $license;

            // display version
            $this->abort(implode("\n", $content), 0);
        }
        // -t add a tag to the run
        if ($this->Options->is_set('t')) {
            if (!$this->Options->get('t')) {
                $this->abort("Option -t {tag} may not be empty!");
            }
        }

        #####################################
        # TOP HEADER
        #####################################
        // add version and time to header
        $this->out($this->Session->get('appname') . ' v' . $this->Session->get('version') . " - SCRIPT STARTED " . date('Y-m-d H:i:s', $this->Session->get('chrono.session.start')), 'header+');

        // -n dry run
        if ($this->Options->is_set('n')) {
            $this->warn('DRY RUN!!');
        }

        #####################################
        # START OF ENVIRONMENT SECTION
        #####################################
        // add environment section
        $this->out('Environment', 'header');

        #####################################
        # OPERATING SYSTEM
        #####################################
        // operating system
        $this->out('Check local operating system...');
        $operating_system = trim(shell_exec('uname'));

        // check supported operating system
        if (!in_array($operating_system, ['Linux', 'FreeBSD'])) {
            $this->abort("Local OS currently not supported!");
        }
        $this->out($operating_system, 'simple-indent');

        #####################################
        # PHP VERSION
        #####################################
        // Check PHP version
        $this->out('Check PHP version...');

        // full version e.g. 5.5.9-1ubuntu4.17
        $this->Session->set('php.version.full', PHP_VERSION);

        // display version - debugging purposes
        $this->out($this->Session->get('php.version.full'), 'simple-indent');

        // version id e.g. 505070
        $this->Session->set('php.version.id', PHP_VERSION_ID);

        // check PHP version > 7.0
        if ($this->Session->get('php.version.id') < 70000) {
            $this->fail('PHP version 7 or higher required!');
        }

        #####################################
        # COMMANDS
        #####################################
        // some commands may be different depending on operating system
        $Cmd = CmdFactory::create($operating_system);

        // load commands in class
        $this->Cmd = $Cmd;

        #####################################
        # HOSTNAME
        #####################################
        $this->out('Check hostname...');

        // hostname
        $hostname = $this->Cmd->exe('hostname');
        $this->Session->set('local.hostname', $hostname);
        $this->out($hostname, 'simple-indent');
        $this->out();

        #####################################
        # END OF ENVIRONMENT SECTION
        #####################################
        $this->out('OK!', 'simple-success');

        #####################################
        # START INI FILE SECTION
        #####################################
        $this->out("Parse ini file", 'header');

        $config_files = [];

        // load default options
        $defaultfiles = [];
        $defaultfiles[] = '/etc/poppins/default.poppins.ini';
        $defaultfiles[] = getenv("HOME") . '/.poppins/default.poppins.ini';

        // check if default config files exist
        foreach ($defaultfiles as $defaultfile) {

            if (file_exists($defaultfile)) {
                $this->out("Default file $defaultfile found...");
                $config_files[] = $defaultfile;
            }
        }

        // require the config file option
        if (!$this->Options->is_set('c')) {
            $this->abort("Option -c {configfile} is required!");
        } else {
            // get configfile option
            $configfile = $this->Options->get('c');
        }

        // check if the config file exists
        if (!file_exists($configfile)) {
            $this->abort("Config file not found!");
        }
        // config file must match naming convention
        elseif (!preg_match('/^.+\.poppins\.ini$/', $configfile)) {
            $this->abort("Wrong ini file format: {hostname}.poppins.ini!");
        }
        // ok, parse the file
        else {
            $this->out('Check ini file...');

            // get the full path of the file
            $configfile_full_path = $this->Cmd->exe('readlink -nf ' . $configfile);
            $this->out(' ' . $configfile_full_path);

            $config_files[] = $configfile;

            #####################################
            # VALIDATE CONFIG
            #####################################
            foreach ($config_files as $f) {
                //check for illegal comments in ini file
                $lines = file($f);
                $i = 1;
                foreach ($lines as $line) {
                    // lines may not start with hash
                    if (preg_match('/^#/', $line)) {
                        $this->fail("Error on line $i in $f. Illegal comment, use semicolon!");
                    }
                    $i++;
                }
            }

            #####################################
            # PARSE CONFIG
            #####################################
            $scanner_mode = INI_SCANNER_TYPED; # INI_SCANNER_NORMAL

            $config = [];

            // merge configs
            foreach ($config_files as $f) {
                $config_replace = parse_ini_file($f, 1, $scanner_mode);
                $config = array_replace_recursive($config, $config_replace);
            }

            // this variable will be false in case of an error
            if (!$config) {
                $error = error_get_last();
                $this->fail('Error parsing ini file! Syntax? Message: ' . $error['message']);
            }

            // store the config
            $this->Config->update($config);

            #####################################
            # GET SKELETON FROM TEMPLATE
            #####################################
            $json_file = dirname(__FILE__) . '/../ini.json';
            if (!file_exists($json_file)) {
                $this->fail('Cannot find required json file ' . $json_file);
            }
            $contents = file_get_contents($json_file);
            $json = json_decode($contents, true);
            if (!$json) {
                $this->fail('Cannot parse json file:"' . json_last_error_msg() . '"!');
            }

            $directive_skeleton = [];

            // get all the values
            foreach ($json['sections'] as $section) {
                $section_name = $section['name'];

                // skip if not array
                if (@!is_array($section['directives'])) {
                    continue;
                }

                foreach ($section['directives'] as $directive) {
                    $directive_name = $directive['name'];
                    $directive_skeleton[] = "$section_name-$directive_name::";
                }

            }

            #####################################
            # ADD SKELETON FROM CONFIG FILE
            #####################################
            // iterate the config file
            foreach ($this->Config->get() as $section_name => $v) {
                foreach ($v as $directive_name => $vv) {
                    $string = "$section_name-$directive_name::";
                    if (!in_array($string, $directive_skeleton)) {
                        $directive_skeleton[] = $string;
                    }

                }
            }

            #####################################
            # OVERRIDE OPTIONS
            #####################################
            //store override options
            $cli_options = getopt(implode('', $cli_short_options), $directive_skeleton);
            //allow a yes or no value in override
            foreach ($cli_options as $k => $v) {
                if (in_array($v, ['yes', 'true'])) {
                    $cli_options[$k] = '1';
                } elseif (in_array($v, ['no', 'false'])) {
                    $cli_options[$k] = '';
                }
            }
            $this->Options->update($cli_options);

            //override configuration with cli options
            foreach ($cli_options as $k => $v) {
                if (in_array("$k::", $directive_skeleton)) {
                    $p = explode('-', $k);
                    // section
                    $k1 = $p[0];
                    unset($p[0]);
                    // directive
                    $k2 = implode('-', $p);
                    $this->Config->set([$k1, $k2], $v);
                }
            }
        }

        #####################################
        # CHECK CONFIGURATION SYNTAX
        #####################################
        //trim spaces
        $this->out("Check configuration syntax...");
        foreach ($this->Config->get() as $k => $v) {
            //do not validate if included or excluded directories
            if (in_array($k, ['included', 'excluded'])) {
                //trim commas
                foreach ($v as $kk => $vv) {
                    $this->Config->set([$k, $kk], preg_replace('/\s?,\s?/', ',', $vv));
                }
            } else {
                //loop thru key/value pairs
                foreach ($v as $kk => $vv) {
                    //check for white space
                    $vv1 = str_replace(" ", "", $vv);
                    if ($vv != $vv1) {
                        $this->warn('Config values may not contain spaces. Value for ' . $kk . ' [' . $k . '] is trimmed!');
                        $this->Config->set([$k, $kk], $vv1);
                    }
                    //No trailing slashes for certain sections
                    $blacklist = ['local', 'included', 'excluded'];
                    if (in_array($k, $blacklist) && preg_match('/\/$/', $vv)) {
                        //$this->fail("No trailing slashes allowed in config file! $kk = $vv...");
                    }
                }
            }
        }

        #####################################
        # ABORT IF NO ACTION NEEDED
        #####################################
        //check if there is anything to do
        if (!count($this->Config->get('included')) && !$this->Config->get('mysql.enabled')) {
            $this->fail("No directories configured for backup nor mysql configured. Nothing to do...");
        }

        #####################################
        # CHECK REMOTE HOST
        #####################################
        //check remote host - no need to get any further if not configured
        if ($this->Config->get('remote.ssh')) {
            if ($this->Config->get('remote.host') == '') {
                $this->fail("Remote host is not configured!");
            }
        } else {
            // no need to set the remote host if ssh not enabled. it's only confusing.
            if ($this->Config->get('remote.host') != '') {
                $this->notice("Remote host is configured. However, ssh connection is disabled.");
            }
            // get the hostname of the localhost
            $hostname = $this->Session->get('local.hostname');
            if (!empty($hostname)) {
                $this->Config->set('remote.host', $hostname);
            } else {
                $this->Config->set('remote.host', 'localhost');
            }
        }

        #####################################
        # VALIDATE BASED ON INI FILE
        #####################################
        //iterate sections
        foreach ($json['sections'] as $section) {
            if (!$this->Config->is_set($section['name'])) {
                $this->fail('Section [' . $section['name'] . '] is not set!');
            }
            //add snapshots to validation
            if ($section['name'] == 'snapshots') {
                foreach (array_keys($this->Config->get('snapshots')) as $k) {
                    $section['directives'][] = ['name' => $k, 'validate' => ['integer' => 'error']];
                }
            }
            // iterate all directives
            if (isset($section['directives']) && is_array($section['directives'])) {
                //validate directives
                foreach ($section['directives'] as $directive) {
                    //skip validation if dependency is not met
                    if (isset($directive['depends']) && !$this->Config->get($directive['depends'])) {
                        continue;
                    }
                    //check if section and directive is set
                    if ($this->Config->is_set([$section['name'], $directive['name']])) {
                        // set value
                        $value = $this->Config->get([$section['name'], $directive['name']]);

                        #####################################
                        # WEIRD CHARACTERS
                        #####################################
                        // allow database patterns and regex
                        if (isset($directive['validate']['databases_include_type'])) {
                            $patterns = explode(',', $value);
                            foreach ($patterns as $pattern) {
                                if (!preg_match('/^\/.+\/$/', $pattern) && !preg_match('/^[^\/\.]+$/', $pattern)) {
                                    $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] has illegal database value: "' . $pattern . '"';
                                    $this->fail($message);
                                }
                            }
                        }
                        // allow table patterns and regex
                        elseif (isset($directive['validate']['tables_include_type'])) {
                            $patterns = (preg_match('/,/', $value)) ? explode(',', $value) : [$value];
                            //possibly single value
                            foreach ($patterns as $pattern) {
                                if (!preg_match('/^\/.+\/$/', $pattern) && !preg_match('/^[^\.]+\.[^\.]+$/', $pattern)) {
                                    $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] has illegal table value: "' . $pattern . '"';
                                    $this->fail($message);
                                }
                            }
                        }
                        // no ".." dir expansion
                        elseif (preg_match("/\/?\.\.\/?/", $value, $matches)) {
                            $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] has an illegal value: "' . $matches[0] . '"';
                            $this->fail($message);
                        }
                        // other weird characters
                        elseif (!Validator::contains_allowed_characters($value)) {
                            //check bad characters - #&;`|*?~<>^()[]{}$\, \x0A and \xFF. ' and " are escaped
                            $escaped = escapeshellcmd($value);
                            // weird characters found
                            if ($value != $escaped) {
                                #####################################
                                # allow tilda
                                #####################################
                                //allow tilde in home paths!
                                $validate_tilda_path = false;
                                $possible_tilda_paths = ['home_path', 'absolute_path|home_path', 'mysql_paths'];
                                foreach ($possible_tilda_paths as $path) {
                                    if (isset($directive['validate'][$path])) {
                                        $validate_tilda_path = true;
                                        break;
                                    }
                                }
                                // validate special path
                                if ($validate_tilda_path) {
                                    $paths = explode(',', $value);
                                    foreach ($paths as $p) {
                                        $p = trim($p);
                                        if (preg_match('/^~/', $p)) {
                                            if (!Validator::is_relative_home_path($p)) {
                                                $this->fail("Directive " . $directive['name'] . " [" . $section['name'] . "] is not a home path!");
                                            }
                                            $p = preg_replace('/\~/', '', $p);
                                        }
                                        //check characters
                                        if (!Validator::contains_allowed_characters($p)) {
                                            $this->fail("Illegal character found in mysql configdir path '$value' in directive " . $directive['name'] . " [" . $section['name'] . "]!");
                                        }
                                    }
                                } else {
                                    $this->fail("Illegal path character found in string '$value' in directive " . $directive['name'] . " [" . $section['name'] . "]!");
                                }
                            }
                        }

                        #####################################
                        # ALLOWED
                        #####################################
                        if (isset($directive['validate']['allowed'])) {
                            $allowed = $directive['validate']['allowed'];
                            $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an allowed value. Use values "' . implode('/', $allowed) . '"!';
                            if (!in_array($value, $allowed)) {
                                $this->fail($message);
                            }
                        }

                        #####################################
                        # LIST
                        #####################################
                        if (isset($directive['validate']['list'])) {
                            $list = $directive['validate']['list'];
                            foreach (explode(',', $value) as $v) {
                                if (!in_array($v, $list)) {
                                    $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an allowed list value: "' . $v . '". Use values "' . implode('/', $list) . '"!';
                                    $this->fail($message);
                                }
                            }
                        }

                        #####################################
                        # BOOLEAN
                        #####################################
                        if (isset($directive['validate']['boolean'])) {
                            $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not a valid boolean. Use values yes/no without quotes! ';

                            if (!is_bool($value)) {
                                # this should actually be the default behaviour if parse_ini_file is set to typed
                                if (in_array($value, ['on', 'yes', 'true', 1])) {
                                    # convert to boolean
                                    $this->Config->set([$section['name'], $directive['name']], true);

                                    # warning
                                    $message .= 'Converting to boolean (yes)...';
                                    $this->notice($message);
                                } elseif (in_array($value, ['off', 'no', 'false', 0])) {
                                    # convert to boolean
                                    $this->Config->set([$section['name'], $directive['name']], false);

                                    # warning
                                    $message .= 'Converting to boolean (no)...';
                                    $this->notice($message);
                                }
                                # we cannot convert it
                                else {
                                    switch ($directive['validate']['boolean']) {
                                        case 'error':
                                            $this->fail($message);
                                            break;
                                        case 'warning':
                                            $this->warn($message);
                                            break;
                                        case 'notice':
                                            $this->notice($message);
                                            break;
                                    }
                                }
                            }
                        }

                        #####################################
                        # INTEGER
                        #####################################
                        if (isset($directive['validate']['integer'])) {
                            if (!is_integer($value)) {
                                # it is a string, but at least it is an integer
                                if (preg_match("/^[0-9]+$/", $value)) {
                                    $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an integer! Converting to integer...';
                                    $this->notice($message);

                                    # convert to integer
                                    $this->Config->set([$section['name'], $directive['name']], intval($value));
                                } else {
                                    $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an integer!';

                                    switch ($directive['validate']['integer']) {
                                        case 'error':
                                            $this->fail($message);
                                            break;
                                        case 'warning':
                                            $this->warn($message);
                                            break;
                                        case 'notice':
                                            $this->notice($message);
                                            break;
                                    }
                                }
                            }
                        }

                        #####################################
                        # ABSOLUTE OR RELATIVE HOME PATH
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['absolute_path|home_path'])) {
                            if (!Validator::is_absolute_path($value) && (!Validator::is_relative_home_path($value))) {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an absolute/home path!';
                                if ($directive['validate']['absolute_path|home_path'] == 'warning') {
                                    $this->warn($message);
                                } else {
                                    $this->fail($message);
                                }
                            }
                        }

                        #####################################
                        # 1 ABSOLUTE PATH
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['absolute_path'])) {
                            if (!Validator::is_absolute_path($value)) {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an absolute path!';
                                if ($directive['validate']['absolute_path'] == 'warning') {
                                    $this->warn($message);
                                } else {
                                    $this->fail($message);
                                }
                            }
                        }

                        #####################################
                        # 1 ABSOLUTE PATH OR EMPTY
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['absolute_path?'])) {
                            if (!empty($value) && !Validator::is_absolute_path($value)) {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not an absolute path!';
                                if ($directive['validate']['absolute_path?'] == 'warning') {
                                    $this->warn($message);
                                } else {
                                    $this->fail($message);
                                }
                            }
                        }

                        #####################################
                        # NO TRAILING SLASH
                        #####################################
                        //exactly one absolute path
                        if (isset($directive['validate']['no_trailing_slash'])) {
                            if (Validator::contains_trailing_slash($value)) {
                                $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] may not have a trailing slash!';
                                if ($directive['validate']['no_trailing_slash'] == 'warning') {
                                    $this->warn($message);
                                } else {
                                    $this->fail($message);
                                }
                            }
                        }

                        #####################################
                        # MYSQL
                        #####################################
                        if ($this->Config->get('mysql.enabled')) {
                            #####################################
                            # MULTIPLE MYSQL PATHS
                            #####################################
                            if (isset($directive['validate']['mysql_paths'])) {
                                //set to home if empty
                                if (empty($value)) {
                                    $this->Config->set([$section['name'], $directive['name']], $directive['default']);
                                } else {
                                    $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] contains an illegal path!';
                                    $paths = explode(',', $value);
                                    if (!count($paths)) {
                                        $paths = [$value];
                                    }
                                    foreach ($paths as $path) {
                                        if ($path != '~' && !Validator::is_absolute_path($path) && (!Validator::is_relative_home_path($path))) {
                                            if ($directive['validate']['mysql_paths'] == 'warning') {
                                                $this->warn($message);
                                            } else {
                                                $this->fail($message);
                                            }
                                        }
                                    }
                                }
                            }

                            #####################################
                            # include types
                            #####################################
                            //exactly one absolute path
                            if (isset($directive['validate']['mysql_include_types'])) {

                            }
                        }
                    }

                    #####################################
                    # SET TO DEFAULT VALUE
                    #####################################
                    else {
                        $message = 'Directive ' . $directive['name'] . ' [' . $section['name'] . '] is not set!';
                        // check if default value
                        if (isset($directive['default'])) {
                            $this->Config->set([$section['name'], $directive['name']], $directive['default']);
                            //convert boolean to yes/no
                            if (is_bool($directive['default'])) {
                                $default_displayed = ($directive['default']) ? 'yes' : 'no';
                            }
                            # this is not supported - json_decode function sets integers as strings
                            elseif (is_int($directive['default'])) {
                                $default_displayed = (empty($directive['default'])) ? "" : $directive['default'];
                            } else {
                                // TODO - add quotes if a distinction can be made between integers and strings
                                # $default_displayed = (empty($directive['default']))? "''":"'".$directive['default']."'";
                                $default_displayed = (empty($directive['default'])) ? "" : $directive['default'];
                            }
                            // ignore some directives e.g. mysql include/exclude stuff
                            if (!isset($directive['ignore-missing']) || $directive['ignore-missing'] !== true) {
                                $this->notice($message . ' Using default value (' . $default_displayed . ').');
                            }
                        } else {
                            $this->fail($message);
                        }
                    }
                }
            }
        }

        #####################################
        # VALIDATE INCLUDED/EXCLUDED
        #####################################
        //check if empty
        foreach (['included', 'excluded'] as $section) {
            foreach ($this->Config->get($section) as $k => $v) {
                if (empty(trim($v))) {
                    $this->fail("Value may not be empty in [$section] section!");
                }
            }
        }
        //check trailing slashes
        foreach (['included', 'excluded'] as $section) {
            foreach ($this->Config->get($section) as $k => $v) {
                if (Validator::contains_trailing_slash(trim($k))) {
                    $this->fail("Directive '" . $k . "' in [$section] section may not contain a trailing slash!");
                } elseif (Validator::contains_trailing_slash(trim($v))) {
                    $this->fail("Value '" . $v . "' in [$section] section may not contain a trailing slash!");
                }
            }
        }
        //validate spaces in keys of included section
        foreach ($this->Config->get('included') as $k => $v) {
            $k1 = str_replace(' ', '\ ', stripslashes($k));
            if ($k != $k1) {
                $this->fail("You must escape white space in [included] section!");
            }
        }
        //validate path of excluded section
        foreach ($this->Config->get('excluded') as $k => $v) {
            $exploded = explode(',', $v);
            foreach ($exploded as $e) {
                if (empty(trim($e))) {
                    $this->fail("Paths in the [excluded] section may not be empty! '$v' not supported!");
                } elseif (preg_match('/^\.?\//', $e)) {
                    $this->fail("You must use a relative path in the [excluded] section! '$v' not supported!");
                } elseif (preg_match('/\/$/', $e)) {
                    $this->fail("Value '" . $e . "' in [exluded] section may not contain a trailing slash!");
                }
            }
        }
        //validate spaces in values of included/excluded section
        foreach (['included', 'excluded'] as $section) {
            foreach ($this->Config->get($section) as $k => $v) {
                $v1 = str_replace(' ', '\ ', stripslashes($v));
                if ($v != $v1) {
                    $this->fail("You must escape white space in [$section] section!");
                }
            }
        }
        //validate included/excluded syntax
        $included = array_keys($this->Config->get('included'));
        $excluded = array_keys($this->Config->get('excluded'));
        foreach ($excluded as $e) {
            if (!in_array($e, $included)) {
                $this->fail("Unknown excluded directory index \"$e\"!");
            }
        }
        //validate snapshot config
        $this->out("Check snapshot config...");
        foreach ($this->Config->get('snapshots') as $k => $v) {
            //check syntax of key
            if ($k != 'incremental' && !preg_match('/^[0-9]+-(' . implode("|", $this->Session->get('intervals')) . ')$/', $k)) {
                $this->fail("Error in snapshot configuration, $k not supported!");
            }
        }

        $DirectoryStructure = DirectoryStructureFactory::create($this);

        #####################################
        # SETUP LOG DIR
        #####################################
        //set log dir
        $this->out('Setup log dir..');
        $DirectoryStructure->setup_log_dir();

        #####################################
        # HANDLE IPV6 ADRESSES
        #####################################

        if ($this->Config->get('remote.ipv6') === true) {

            $HostAndInterfaceArray = explode('%', $this->Config->Get('remote.host'));
            $HostWithoutInterface = $HostAndInterfaceArray[0];
            $InterfaceArraySize = count($HostAndInterfaceArray);

            // test if remote host could be specified as an ipv address (punctuation is illegal in host names as per RFC 1123
            if (strpos($HostWithoutInterface, ':')) {
                if (filter_var($HostWithoutInterface, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    // user has set a valid ipv6 address as remote host.
                    // is it link-local?
                    if (preg_match("/^fe80::/", $HostWithoutInterface)) {
                        // link local. needs interface name. did the user set it?
                        if ($InterfaceArraySize != 2) {
                            $this->fail('Remote host error: you are using a link-local ipv6 address without network interface. The notation is: <address>%<interface> eg: fe80::1234:abcd:00c0:ffee%eth0');
                        }
                    }
                } else {
                    // ipv6 failed to validate for other reasons than not specifying the interface name with it
                    $this->fail('Remote host error: name contains colons but did not validate as an ipv6 address');
                }
            }
        }

        #####################################
        # SET SSH CONNECTION
        #####################################
        //set remote user - must be set in any case (fdisk check)
        $remote_user = ($this->Config->get('remote.user')) ? $this->Config->get('remote.user') : $this->Cmd->exe('whoami');

        $this->Config->set('remote.user', $remote_user);

        // only applicable for ssh
        if ($this->Config->get('remote.ssh')) {
            // ssh options
            $ssh_port = ($this->Config->get('remote.port')) ? $this->Config->get('remote.port') : 22;

            $ssh_ipv6 = $this->Config->get('remote.ipv6') ? '-6' : '';
            $this->Session->Set('ssh.options', "$ssh_ipv6 -p $ssh_port -o BatchMode=yes -o ConnectTimeout=15 -o TCPKeepAlive=yes -o ServerAliveInterval=30");
            $this->out('Check remote parameters...');

            //first ssh attempt
            $this->out('Check ssh connection...');
            //obviously try ssh at least once :)
            $attempts = 1;
            //retry attempts on connection fail
            if ($this->Config->get('remote.retry-count')) {
                $attempts += (integer) $this->Config->get('remote.retry-count');
            }
            //allow for a timeout
            $timeout = 0;
            if ($this->Config->get('remote.retry-timeout')) {
                $timeout += (integer) $this->Config->get('remote.retry-timeout');
            }
            $i = 1;
            $success = false;
            $user = $this->Config->get('remote.user');
            $host = $this->Config->get('remote.host');
            while ($i <= $attempts) {
                $this->Cmd->exe("'echo OK'", true);
                if ($this->Cmd->is_error()) {
                    $this->warn("SSH login attempt $user@$host failed!");
                    if ($i != $attempts) {
                        $this->out("Will retry ssh attempt " . ($i + 1) . " of $attempts in $timeout second(s)...\n");
                        sleep($timeout);
                    }
                    $i++;
                } else {
                    $success = true;
                    break;
                }
            }
            //check if successful
            if (!$success) {
                $this->fail("SSH connection $user@$host failed on port $ssh_port! Generate a key with ssh-keygen and ssh-copy-id to set up a passwordless ssh connection?");
            }
        }

        //get remote os
        $this->Config->set('remote.os', $this->Cmd->exe("uname", true));
        //get distro
        // try /etc/*release
        $output = $this->Cmd->exe("'cat /etc/*release'", true);
        if ($this->Cmd->is_error()) {
            $output = $this->Cmd->exe("'lsb_release -a'", true);
            if ($this->Cmd->is_error()) {
                $this->fail('Cannot discover remote distro!');
            }
        }
        foreach (['Ubuntu', 'Debian', 'FreeBSD', 'OpenIndiana', 'Red Hat', 'CentOS', 'Fedora', 'Manjaro', 'Arch'] as $d) {
            if (preg_match("/$d/i", $output)) {
                $this->Config->set('remote.distro', $d);
                break;
            }
        }

        //check pre backup script
        if (!$this->Config->is_set('remote.pre-backup-script')) {
            $this->warn('Directive pre-backup-script [remote] is not configured!');
        }
        //check if set and if so, validate onfail action
        elseif ($this->Config->get('remote.pre-backup-script') != '') {
            //check if path is set correctly
            if (!Validator::is_absolute_path($this->Config->get('remote.pre-backup-script'))) {
                $this->fail("pre-backup-script must be an absolute path!");
            }
            //check if action is set correctly
            if (!in_array($this->Config->get('remote.pre-backup-onfail'), ['abort', 'continue'])) {
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
        if (in_array($remote_distro, ['Debian', 'Ubuntu'])) {
//            $dependencies['hard']['remote']['aptitude'] = 'aptitude --version';
        }
        //Red Hat - Fedora
        if (in_array($remote_distro, ['Red Hat', 'CentOS', 'Fedora'])) {
            //yum is nice though rpm will suffice, no hard dependency needed
            //$dependencies['hard']['remote']['yum-utils'] = 'yumdb --version';
        }
        //Arch - Manjaro
        if (in_array($remote_distro, ['Arch', 'Manjaro'])) {
            $dependencies['hard']['remote']['pacman'] = 'pacman --version';
        }
        $dependencies['hard']['remote']['rsync'] = 'rsync --version';
        $dependencies['hard']['remote']['bash'] = 'bash --version';
        $dependencies['soft']['remote']['sfdisk'] = 'sfdisk -v';

        //check mysql package
        if ($this->Config->get('mysql.enabled')) {
            $dependencies['hard']['remote']['mysql'] = 'mysql --version';
        }

        //local
        $dependencies['hard']['local']['bash'] = 'bash --version';
        $dependencies['hard']['local']['gzip'] = 'gzip --version';
        $dependencies['hard']['local']['rsync'] = 'rsync --version';
        $dependencies['hard']['local']['grep'] = '{GREP} --version';

        //iterate packages
        foreach ($dependencies as $type => $dependency) {
            foreach ($dependency as $host => $packages) {
                foreach ($packages as $package => $command) {
                    //check if installed
                    $remote = ($host == 'remote') ? true : false;
                    $this->Cmd->exe("$command 2>/dev/null", $remote);
                    if ($this->Cmd->is_error()) {
                        $action = ($type == 'hard') ? 'fail' : 'notice';
                        $this->$action("Package $package installed on $host machine?");
                    }
                }
            }
        }

        // creation of these directories must be in exact order!

        #####################################
        # SETUP ROOT DIR
        #####################################
        //set root dir
        $this->out('Setup root dir..');
        $DirectoryStructure->setup_root_dir();

        #####################################
        # SETUP HOST DIR
        #####################################
        //set host dir - set up as early as possible!
        $this->out('Setup host dir..');
        $DirectoryStructure->setup_host_dir();

        #####################################
        # SETUP RSYNC DIR
        #####################################
        //set syncdir
        $this->out('Setup rsync dir..');
        $DirectoryStructure->setup_rsync_dir();
        $DirectoryStructure->setup_rsync_sub_dirs();

        #####################################
        # CHECK IF RSYNC DIR IS CLEAN
        #####################################
        $unclean_files = [];
        // files subdirectory
        $dir = $this->Config->get('local.rsync_dir') . '/files';
        if (file_exists($dir)) {
            $whitelist = array_map('stripslashes', array_values($this->Config->get('included')));
            $unclean_files = Validator::scan_dir_unclean_files($dir, $whitelist);

            // files found!
            if (count($unclean_files)) {
                foreach ($unclean_files as $file => $type) {
                    $this->notice("Rsync subdirectory '/files' not clean, unknown $type '$file'. Remove or rename to '_$file'..");
                }
            }
        }

        #####################################
        # CHECK IF MYSQL DIR IS CLEAN
        #####################################
        $unclean_files = [];
        // when not enabled, mysql dir should be empty
        if (!$this->Config->get('mysql.enabled')) {
            $dir = $this->Config->get('local.rsync_dir') . '/mysql';
            if (file_exists($dir)) {
                $whitelist = [];
                $unclean_files = Validator::scan_dir_unclean_files($dir, $whitelist);
                if (count($unclean_files)) {
                    foreach ($unclean_files as $file => $type) {
                        $this->notice("MySQL directory $dir not clean, found $type '$file' while mysql is disabled. Remove or rename to '_$file'..");
                    }
                }
            }
        }

        #####################################
        # CHECK IF ARCHIVE DIR IS CLEAN
        #####################################
        $unclean_files = [];
        //check if archive dir is clean
        $dir = $this->Config->get('local.hostdir') . '/archive';
        if (file_exists($dir)) {
            // only snapshot subdirectories allowed
            $whitelist = array_keys($this->Config->get('snapshots'));

            // different implementation for zfs - archive dir is validated later
            if ($this->Config->get('local.snapshot-backend') != 'zfs') {
                $unclean_files = Validator::scan_dir_unclean_files($dir, $whitelist);

                // check if there are any
                if (count($unclean_files)) {
                    foreach ($unclean_files as $file => $type) {
                        $this->notice("Archive directory $dir not clean, unknown $type '$file'. Remove or rename to '_$file'..");
                    }
                }
            }
        }

        #####################################
        # SETUP MYSQL CONFIG & DIRS
        #####################################
        if ($this->Config->get('mysql.enabled')) {
            #####################################
            # SEARCH CONFIG FILES IN DIRS
            #####################################
            $config_dirs = [];
            if ($this->Config->get('mysql.configdirs')) {
                $config_dirs = explode(',', $this->Config->get('mysql.configdirs'));
            }
            // default is home dir
            else {
                $config_dirs[] = '~';
            }
            // iterate config dirs
            $config_files = [];
            //iterate dirs
            foreach ($config_dirs as $config_dir) {
                $this->Cmd->exe("'cd $config_dir' 2>&1", true);
                if ($this->Cmd->is_error()) {
                    $this->warn('Cannot access remote mysql configdir ' . $config_dir . '...');
                } else {
                    $output = $this->Cmd->exe("'ls $config_dir/.my.cnf* 2>/dev/null'", true);
                }
                //check output
                if ($output) {
                    $config_files = array_merge($config_files, explode("\n", $output));
                } else {
                    $this->warn('Cannot find mysql config files in remote dir ' . $config_dir . '...');
                }
            }
            // noting to do, exit
            if (!count($config_files)) {
                $this->fail('Cannot find any mysql config file of pattern .my.cnf*...');
            }

            #####################################
            # MY.CONF CONFIG FILE DIRS
            #####################################
            $config_file_cache = [];
            foreach ($config_files as $config_file) {
                //check if the config file is named correctly
                if (!preg_match('/^.+my\.cnf(\.)*/', $config_file)) {
                    $this->fail('Database config file does not match pattern ".my.cnf*": ' . $config_file);
                }
                //instance - use special name or set default
                $instance = preg_replace('/^.+my\.cnf(\.)*/', '', $config_file);
                // set default dir
                $instance = ($instance) ? $instance : 'default';
                //ignore if file is the same
                $contents = $this->Cmd->exe("'cat $config_file'", true);
                if (in_array($contents, $config_file_cache)) {
                    // notice to user
                    $this->notice("Found duplicate mysql config file $config_file...");
                    // unset this file from the config array
                    $key = array_search($config_file, $config_files);
                    unset($config_files[$key]);
                    // continue the loop
                    continue;
                } else {
                    $config_file_cache[] = $contents;
                }

                #####################################
                # SETUP MYSQLDUMP DIR
                #####################################
                $rsync_dir = $this->Config->get('local.rsync_dir');
                $mysqldump_dir = "$rsync_dir/mysql/$instance";

                // check if dir exists
                if (!is_dir($mysqldump_dir)) {
                    $this->out("Create directory $mysqldump_dir...");
                    $this->Cmd->exe("mkdir -p $mysqldump_dir");
                }

                // empty the dir unless dry run
                elseif (!$this->Options->is_set('n')) {
                    $this->out("Empty directory $mysqldump_dir...");
                    // take precautions when executing an rm command!
                    foreach ([$mysqldump_dir] as $variable) {
                        $variable = trim($variable);
                        if (!$variable || empty($variable) || $variable == '' || preg_match('/^\/+$/', $variable)) {
                            $this->App->fail('Cannot execute a rm command as a variable is empty!');
                        }
                    }
                    $this->Cmd->exe("rm -f $mysqldump_dir/*");
                }
                // load in session
                $this->Session->set('mysql.dumpdir.' . $instance, $mysqldump_dir);
            }
            //store the array in the session
            $this->Session->set('mysql.configfiles', $config_files);

            #####################################
            # VALIDATE INCLUDED/EXCLUDED
            #####################################
            // check if more than 1 config file found
            if (count($this->Session->get('mysql.configfiles')) > 1) {
                foreach (['included', 'excluded'] as $include_type) {
                    foreach (['databases', 'tables'] as $object) {
                        if ($this->Config->get('mysql.' . $include_type . '-' . $object)) {
                            $this->fail("Cannot configure $include_type-$object while using multiple mysql config files!");
                        }
                    }
                }
            }

            #####################################
            # VALIDATE MYSQL OUTPUT
            #####################################
            if (!$this->Config->is_set('mysql.output')) {
                $this->Config->set('mysql.output', 'databases');
            } else {
                $mysql_output = explode(',', $this->Config->get('mysql.output'));
                foreach ($mysql_output as $o) {
                    if (!in_array($o, ['database', 'table', 'csv'])) {
                        $this->fail('Illegal value for mysql output: "' . $o . '"');
                    }
                }
            }
        }
        $this->out();
        $this->out('OK!', 'simple-success');

        ######################################
        # DUMP ALL CONFIG
        #####################################
        $hostname = ($this->Config->get('remote.ssh')) ? '@' . $this->Config->get('remote.host') : '(LOCAL)';
        $this->out('List configuration ' . $hostname, 'header');
        $output = [];
        foreach ($this->Config->get() as $k => $v) {
            if (is_array($v)) {
                ksort($v);
                $output[] = "\n[$k]";
                foreach ($v as $kk => $vv) {
                    $vv = ($vv) ? $vv : '""';
                    $output[] = sprintf("%s = %s", $kk, $vv);
                }
            } else {
                $v = ($v) ? $v : '""';
                $output[] = sprintf("%s = %s", $k, $v);
            }
        }
        $this->out(trim(implode("\n", $output)));
        $this->out();
        $this->out('OK!', 'simple-success');
        $this->out();
        #####################################
        # CREATE LOCK FILE
        #####################################
        // lock file
        $lock_file = $this->Config->get('local.hostdir') . "/LOCK";

        // check for lock
        // ignore lock file in debug mode
        if ($this->Options->is_set('d')) {
            $this->out('Debug mode. Skip LOCK file check...');
        } elseif (file_exists($lock_file)) {

            // output is something like this: 260277.97 72804.11
            $file_contents = @file_get_contents('/proc/uptime');

            // could not determine uptime, feature not supported
            if (empty($file_contents)) {
                $this->fail("LOCK file " . $lock_file . " exists!", 'LOCKED');
            }

            $uptime_in_seconds = (int) floatval($file_contents);
            $current_time_unix_timestamp = date('U');
            $boot_time_unix_timestamp = $current_time_unix_timestamp - $uptime_in_seconds;
            $lock_file_modified_unix_timestamp = date("U", filemtime($lock_file));

            if ($boot_time_unix_timestamp < $lock_file_modified_unix_timestamp) {
                // lock file is present, delete it
                $this->fail("LOCK file " . $lock_file . " exists!", 'LOCKED');
            }

            //create lock file if we got this far
            $this->out('System has rebooted. Remove old LOCK file...');
        }

        // create or modify create time
        $this->out('Create new LOCK file...');
        $this->Cmd->exe("touch " . $lock_file);
    }

    /**
     * Add a message to messages array
     * Add a message to colored messages array if required
     *
     * @param string $message The message
     * @param bool $fgcolor Foreground color
     * @param bool $bgcolor Background color
     */
    public function log($message = '', $fgcolor = false, $bgcolor = false)
    {
        $this->messages[] = $message;
        //output color
        if ($fgcolor) {
            $this->cmessages[count($this->messages) - 1] = [$fgcolor, $bgcolor];
        }

    }

    /**
     * Add a notice
     *
     * @param $message The message
     */
    public function notice($message)
    {
        $this->notices[] = $message;
        $this->out($message, $type = 'notice');
    }

    /**
     * Add style to the message
     *
     * @param string $message The message
     * @param string $type The type of style
     */
    public function out($message = '', $type = 'default')
    {
        $content = [];
        $fgcolor = false;
        $bgcolor = false;

        $header_length = 90;
        $indent_length = 10;
        $notice_length = $header_length - (2 * $indent_length);
        $arrow_length = 4;

        // does not work
        $indent_space = '';
        foreach (range(1, $indent_length) as $i) {
            $indent_space .= ' ';
        }

        // foreach(range(1,10) as $i) $indent_space .= ' ';
        $styles = [];
        $styles['error'] = ['symbol' => '#', 'color' => 'light_red'];
        $styles['warning'] = ['symbol' => '*', 'color' => 'brown'];
        $styles['notice'] = ['symbol' => '~', 'color' => 'blue'];
        $styles['success'] = ['symbol' => '-', 'color' => 'green'];

        switch ($type) {
            case 'final-error':
                $fgcolor = 'light_red';
                $content[] = '';
                $content[] = $message;
                $content[] = '';
                break;
            case 'final-success':
                $fgcolor = 'green';
                $content[] = '';
                $content[] = $message;
                $content[] = '';
                break;
            case 'header':
                $line = '';
                $symbol = "_";
                foreach (range(1, $header_length) as $i) {
                    $line .= $symbol;
                }

                $content[] = '';
                $content[] = str_pad(strtoupper(' ' . $message . ' '), $header_length, $symbol, STR_PAD_BOTH);
                $content[] = '';
                break;
            case 'header+':
                $symbol = ":";
                $line = '';
                foreach (range(1, $header_length) as $i) {
                    $line .= $symbol;
                }

                $content[] = '';
                $content[] = $line;
                $content[] = str_pad(' ' . $message . ' ', $header_length, $symbol, STR_PAD_BOTH);
                $content[] = $line;
                $content[] = '';
                break;
            case 'indent':
                $fgcolor = 'cyan';
                $arrow = '';
                foreach (range(1, $arrow_length) as $i) {
                    $arrow .= '-';
                }

                $content[] = $arrow . "> " . $message;
                break;
            case 'indent-error':
                $fgcolor = 'light_red';
                $arrow = '';
                foreach (range(1, $arrow_length) as $i) {
                    $arrow .= '-';
                }

                $content[] = wordwrap($arrow . "> " . $message, $header_length);
                break;
            case 'indent-notice':
                $fgcolor = 'blue';
                $arrow = '';
                foreach (range(1, $arrow_length) as $i) {
                    $arrow .= '-';
                }

                $content[] = $arrow . "> " . $message;
                break;
            case 'indent-warning':
                $fgcolor = 'brown';
                $arrow = '';
                foreach (range(1, $arrow_length) as $i) {
                    $arrow .= '-';
                }

                $content[] = $arrow . "> " . $message;
                break;
            case 'simple-error':
                $fgcolor = 'light_red';
                $content[] = $message;
                break;
            case 'simple-indent':
                $fgcolor = 'cyan';
                $content[] = ' ' . $message;
                break;
            case 'simple-info':
                $fgcolor = 'cyan';
                $content[] = $message;
                break;
            case 'simple-notice':
                $fgcolor = 'blue';
                $content[] = $message;
                break;
            case 'simple-warning':
                $fgcolor = 'brown';
                $content[] = $message;
                break;
            case 'simple-success':
                $fgcolor = 'green';
                $content[] = $message;
                break;
            case 'error':
            case 'notice':
            case 'warning':
            case 'success':
                $style = $styles[$type];
                $header_length = $notice_length;
                $fgcolor = $style['color'];
                $symbol = $style['symbol'];
                $line1 = str_pad(strtoupper(' ' . strtoupper($type) . ' '), $header_length, $symbol, STR_PAD_BOTH);
                $line2 = '';
                foreach (range(1, $header_length) as $i) {
                    $line2 .= $symbol;
                }
                $message = wordwrap($message, $notice_length, " \n$indent_space");
                $content[] = '';
                $content[] = $line1;
                $content[] = $message;
                $content[] = $line2;
                $content[] = '';
                break;
            case 'final_status':
                $style = $styles[$message];
                $message = strtoupper($message);
                # add indent_space between letters
                $message = implode(' ', str_split($message));
                $message_length = strlen($message);
                $fgcolor = $style['color'];
                $symbol = $style['symbol'];

                $line = '';
                foreach (range(1, $message_length) as $i) {
                    $line .= $symbol;
                }
                $line = str_pad($line, ($header_length / 2) + ($message_length / 2), ' ', STR_PAD_LEFT);
                $message = str_pad($message, ($header_length / 2) + ($message_length / 2), ' ', STR_PAD_LEFT);
                $content[] = '';
                $content[] = $line;
                $content[] = $message;
                $content[] = $line;
                $content[] = '';
                break;
            case 'default':
                $content[] = $message;
                break;
        }

        if (in_array($type, ['notice', 'warning', 'error', 'success'])) {

            $content1 = [];
            foreach ($content as $c) {
                $content1[] = $indent_space . $c;
            }

            $content = $content1;

        }
        $message = implode("\n", $content);
        //log to file
        $this->log($message, $fgcolor, $bgcolor);
    }

    /**
     * Finally quit the application. Once committed to run the backup script,
     * regardless if backups succeeded or failed, this function is invoked.
     * Disk usage is reported if desired, a summary is written on stdout and
     * logged to the appropriate log files.
     *
     * @param bool $error Set to true if backups unsuccessful.
     */
    public function quit($error = false)
    {
        #####################################
        # REPORT DISK USAGE
        #####################################
        if (!$error) {
            //list disk usage
            if ($this->Config->get('log.local-disk-usage')) {
                $this->out('Disk Usage', 'header');
                // disk usage dirs
                $dirs = [];
                // $dirs ['rsync directory (total size)'] []= $this->Config->get('local.rsync_dir');
                $dirs['/files'][] = $this->Config->get('local.rsync_dir') . '/files';
                if ($this->Config->get('mysql.enabled')) {
                    // total mysql
                    $path = $this->Config->get('local.rsync_dir') . '/mysql';
                    $dirs['/mysql'][] = $path;
                    // mysqldumps seperately
                    $scan = scandir($path);
                    foreach ($scan as $s) {
                        if (!in_array($s, ['.', '..'])) {
                            $path1 = $path . '/' . $s;
                            if (is_dir($path1)) {
                                $scan1 = scandir($path1);
                                foreach ($scan1 as $s1) {
                                    if (!in_array($s1, ['.', '..'])) {
                                        $dirs['mysqldumps'][] = $path1 . '/' . $s1;
                                    }

                                }
                            }
                        }
                    }
                }
                // iterate all directories
                $i = 1;
                foreach ($dirs as $section => $sub_dirs) {
                    $this->out("Disk usage of $section...");
                    foreach ($sub_dirs as $sub_dir) {
                        if (file_exists($sub_dir)) {
                            $du = $this->Cmd->exe("du -sh $sub_dir");
                            $this->out("$du");
                        } else {
                            $this->warn('Cannot determine disk usage of ' . $sub_dir);
                        }
                    }
                    // space
                    if ($i < count($dirs)) {
                        $this->out();
                    }
                    $i++;
                }
            }
        }
        #####################################
        # LIST ALL COMMANDS
        #####################################
        if ($this->Options->is_set('d')) {
            $this->out("List commands", 'header');
            $output = [];
            foreach ($this->Cmd->commands as $c) {
                // do not output echo commands
                if (!preg_match('/^echo /', $c)) {
                    $output[] = $c;
                    $output[] = '';
                }
            }
            $this->out(implode("\n", $output));
        }
        #####################################
        # TIMED RUNS
        #####################################
        $this->out('Run', 'header');
        // mark time
        $this->Session->set('chrono.session.stop', date('U'));
        // output all times
        foreach ($this->Session->get('chrono') as $type => $times) {
            // skip if no end time
            if (!$this->Session->is_set(['chrono', $type, 'stop'])) {
                continue;
            }
            // title
            $this->out($type);
            // create message
            $message = [];
            foreach (['start', 'stop'] as $s) {
                $message[] = $s . ': ' . date('Y-m-d H:i:s', $this->Session->get(['chrono', $type, $s]));
            }
            $lapse = ($this->Session->get(['chrono', $type, 'stop']) - $this->Session->get(['chrono', $type, 'start']));
            $this->Session->set(['chrono', $type, 'lapse'], gmdate('H:i:s', $lapse));
            $message[] = 'elapsed (HH:MM:SS): ' . $this->Session->get(['chrono', $type, 'lapse']);
            $this->out(implode(', ', $message), 'simple-indent');
            $this->out();
        }
        #####################################
        # SUMMARY
        #####################################
        $this->out('Summary', 'header');
        //report notices
        $notices = count($this->notices);
        //output all notices
        if ($notices) {
            $this->out("NOTICES (" . $notices . ")", 'simple-notice');
            foreach ($this->notices as $n) {
                $this->out($n, 'indent-notice');
            }
            $this->out();
        }
        //report warnings
        $warnings = count($this->warnings);
        //output all warnings
        if ($warnings) {
            $this->out("WARNINGS (" . $warnings . ")", 'simple-warning');
            foreach ($this->warnings as $w) {
                $this->out($w, 'indent-warning');
            }
            $this->out();
        }
        //report errors
        $errors = count($this->errors);
        if ($errors) {
            $this->out("ERRORS (" . $errors . ")", 'simple-error');
            foreach ($this->errors as $e) {
                $this->out($e, 'indent-error');
            }
            $this->out();
        }
        //log message
        $lhost = $this->Session->get('local.hostname');
        $rhost = $this->Config->get('remote.host');

        if (!$error) {
            if ($this->Options->is_set('n')) {
                $this->out("Dry ryn on $lhost ran successfully for host $rhost...", 'final-success');
            } else {
                $this->out("Backup on $lhost ran successfully for host $rhost...", 'final-success');
            }
        } else {
            $this->out("Backup on $lhost failed for host $rhost...", 'final-error');
        }
        #####################################
        # EXIT STATUS
        #####################################
        // final state
        if ($error) {
            $exit_status = 'error';
        } elseif (count($this->warnings)) {
            $exit_status = 'warning';
        } elseif (count($this->notices)) {
            $exit_status = 'notice';
        } else {
            $exit_status = 'success';
        }

        $this->out($exit_status, 'final_status');
        #####################################
        # ADD TAG
        #####################################
        if ($this->Options->is_set('t') && $this->Options->get('t')) {
            $tag = $this->Options->get('t');

        } else {
            $tag = '(untagged)';
        }
        $this->out("Session tagged as: $tag");

        //final header
        $this->out($this->Session->get('appname') . ' v' . $this->Session->get('version') . " - SCRIPT ENDED " . date('Y-m-d H:i:s'), 'header+');
        #####################################
        # COLORIZE OUTPUT
        #####################################
        //colorize output
        $content = [];
        $i = 0;
        foreach ($this->messages as $m) {
            if (@isset($this->cmessages[$i])) {
                $content[] = $this->colorize($m, $this->cmessages[$i][0], $this->cmessages[$i][1]);
            } else {
                $content[] = $m;
            }
            $i++;
        }
        #####################################
        # CLEANUP LOCK FILE
        #####################################
        //remove LOCK file if exists
        if ($error != 'LOCKED' && file_exists(@$this->Config->get('local.hostdir') . "/LOCK")) {
            $content[] = "Remove LOCK file...";

            // take precautions when executing an rm command!
            foreach ([$this->Config->get('local.hostdir')] as $variable) {
                $variable = trim($variable);
                if (!$variable || empty($variable) || $variable == '' || preg_match('/^\/+$/', $variable)) {
                    $this->App->fail('Cannot execute a rm command as a variable is empty!');
                }
            }
            $this->Cmd->exe('{RM} ' . $this->Config->get('local.hostdir') . "/LOCK");
        }
        #####################################
        # WRITE LOG TO FILES
        #####################################
        //write to log
        if (is_dir($this->Config->get('local.logdir'))) {
            if ($this->Config->get('remote.host')) {
                // create log file
                $host = ($this->Config->get('local.hostdir-name')) ? $this->Config->get('local.hostdir-name') : $this->Config->get('remote.host');
                $logfile_host = $this->Config->get('local.logdir') . '/' . $host . '.' . date('Y-m-d_His', $this->Session->get('chrono.session.start')) . '.poppins.' . $exit_status . '.log';
                $logfile_app = $this->Config->get('local.logdir') . '/poppins.log';
                $content[] = 'Create logfile for host ' . $logfile_host . '...';
                //create file
                $this->Cmd->exe("touch " . $logfile_host);

                if ($this->Cmd->is_error()) {
                    $content[] = 'WARNING! Cannot write to host logfile. Cannot create log file!';
                } else {
                    $success = file_put_contents($logfile_host, implode("\n", $this->messages) . "\n");
                    if (!$success) {
                        $content[] = 'WARNING! Cannot write to host logfile. Write protected?';
                    }
                    //write to application log
                    $this->Cmd->exe("touch " . $logfile_app);
                    if ($this->Cmd->is_error()) {
                        $content[] = 'WARNING! Cannot write to application logfile. Cannot create log file!';
                    }
                    $m = [];
                    $m['timestamp'] = date('Y-m-d H:i:s');
                    $m['host'] = $host;
                    $m['result'] = strtoupper($exit_status);
                    $m['lapse'] = $this->Session->get('chrono.session.lapse');
                    $m['logfile'] = $logfile_host;
                    //compress host logfile?
                    if ($this->Config->get('log.compress')) {
                        $content[] = 'Compress log file...';
                        $this->Cmd->exe("gzip " . $logfile_host);
                        //append suffix in log
                        $m['logfile'] .= '.gz';
                    }
                    $m['version'] = $this->Session->get('version');
                    // add tag to entry
                    if ($this->Options->is_set('t') && $this->Options->get('t')) {
                        $m['tag'] = $this->Options->get('t');
                    }
                    foreach ($m as $k => $v) {
                        $m[$k] = '"' . $v . '"';
                    }
                    $message = implode(' ', array_values($m)) . "\n";
                    $content[] = 'Add "' . $exit_status . '" to logfile ' . $logfile_app . '...';
                    $success = file_put_contents($logfile_app, $message, FILE_APPEND | LOCK_EX);
                    if (!$success) {
                        $content[] = 'WARNING! Cannot write to application logfile. Write protected?';
                    }
                }
            } else {
                $content[] = 'WARNING! Cannot write to host logfile. Remote host is not configured!';
            }
        } else {
            $content[] = 'WARNING! Cannot write to logfile. Log directory not created!';
        }
        #####################################
        # QUIT
        #####################################
        //be polite
        $content[] = "Bye...";
        //last newline
        $content[] = "";
        //output
        print(implode("\n", $content));

        $exit_code = ($exit_status == 'error') ? 1 : 0;
        exit($exit_code);
    }

    /**
     * Add a warning
     *
     * @param $message The message
     */
    public function warn($message)
    {
        $this->warnings[] = $message;
        $this->out($message, $type = 'warning');
    }

}
