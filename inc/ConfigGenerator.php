<?php
/**
 * File ConfigGenerator.php
 *
 * @package    Poppins
 * @license    http://www.gnu.org/licenses/gpl-3.0.en.html  GNU Public License
 * @author     Bruno Dooms, Frank Van Damme
 */


/**
 * Class ConfigGenerator creates config file
 *
 */
class ConfigGenerator
{
    // Cmd class
    private $Cmd;

    // Config class
    private $Config;

    // Dialog class
    private $Dialog;

    // Settings class
    private $Session;

    // documentation
    private $documentation;

    // ini_sekeleton
    private $ini_skeleton;

    function __construct()
    {
        // Config
        $this->Config = new Config();

        // some commands may be different depending on operating system
        $operating_system = trim(shell_exec('uname'));
        $this->Cmd = CmdFactory::create($operating_system);

        $this->Session = Session::get_instance();

        $this->Dialog = new Dialog($this->Cmd);

        $this->documentation = [];

        $this->ini_skeleton = [];
    }

    function init()
    {
        #####################################
        # GET SKELETON FROM TEMPLATE
        #####################################
        $json_file = dirname(__FILE__) . '/../ini.json';
        if (!file_exists($json_file))
        {
            $this->fail('Cannot find required json file ' . $json_file);
        }
        $content = file_get_contents($json_file);
        $ini_template_json = json_decode($content, true);
        if (!$ini_template_json)
        {
            $this->fail('Cannot parse json file:"' . json_last_error_msg() . '"!');
        }

        // get all the values
        foreach ($ini_template_json['sections'] as $section)
        {
            $section_name = $section['name'];

            // skip if not array
            if (@!is_array($section['directives']))
            {
                continue;
            }

            foreach ($section['directives'] as $directive)
            {
                $directive_name = $directive['name'];

                $index = $section_name . '.' . $directive_name;

                $this->ini_skeleton[$index] = $directive;

                $directive_default = (isset($directive['default'])) ? $directive['default'] : '';
                $this->Config->set($section_name . '.' . $directive_name, $directive_default);

            }

        }

        #####################################
        # EXAMPLE FILE
        #####################################
        $example_file = dirname(__FILE__) . '/../docs/example.poppins.ini';
        if (!file_exists($example_file))
        {
            $this->fail('Cannot find required example file ' . $example_file);
        }

        // get content as string
        $example_file_as_string = file_get_contents($example_file);

        // match periodic backups syntax - use s flag to not stop at newline
//        preg_match('/; PERIODIC SNAPSHOTS *\n(;[^;\n]*\n)+/s', $example_file_as_string, $matches);
//        $syntax = $matches[0];

        // get content as array
        $example_file_as_array = file($example_file);
        if (!count($example_file_as_array))
        {
            $this->fail('Cannot get content of example file!');
        }


        $text = [];
        $section = '';
        $i = 0;
        foreach($example_file_as_array as $line)
        {
            $line = trim($line);

            // reset text
            if(empty($line))
            {
                // intro
                if($i == 2)
                {
                    $this->documentation['poppins-intro'] = $text;
                }

                // reset text
                $text = [];
                // raise counter and continue
                $i++;
                continue;
            }

            $index = '';

            // get all text lines, starting with ;
            if (preg_match('/^;/', $line) && !preg_match('/^;;/', $line))
            {
                $text []= trim($line);
            }



            // lines starting with a [, i.e. a section
            if (preg_match('/^\[/', $line))
            {
                preg_match('/[^\[\]]+/', $line, $match);
                $section = $match[0];
                $index = '['.$section.']';
                //                die($index);
            }
            // lines starting with a normal character, i.e. a directive
            elseif (preg_match('/^[a-z]+/', $line))
            {
                preg_match('/^[^ ]+/', $line, $match);
                $index = $section.'.'.$match[0];
            }

            // save text in an array
            if(!empty($index))
            {
                $this->documentation[$index] = $text;
                $text = [];
            }
        }

        #####################################
        # DIALOGS START
        #####################################
        $message = $this->get_message('poppins-intro');

        $continue = $this->Dialog->yesno($message, 'Poppins v'.$this->Session->get('version.base'), true, ['yes'=>'Continue', 'no'=>'Abort']);

        // abort
        if (!$continue)
        {
            $this->quit();
        }

        #####################################
        # ITERATE SECTIONS
        #####################################
        $sections = ['remote', 'local', 'included', 'snapshots', 'meta', 'log', 'rsync', 'mysql'];
//        $sections = ['included', 'snapshots', 'mysql']; # dev
//        $sections = ['mysql']; # dev
        foreach ($sections as $section)
        {
            #####################################
            # SECTION START
            #####################################
            // initiate the message
            $message = [];
            $message [] = $this->get_message('[' . $section . ']');

            $enable_section = true;

            switch ($section)
            {
                case 'meta':
                case 'log':
                case 'rsync':
                    $message [] = '';
                    $message [] = $this->color('green') . 'The [' . $section . '] section has reasonable defaults. Would you like to configure it anyway?';
                    $enable_section = $this->Dialog->yesno(implode("\n", $message), $section, false);
                    break;
                case 'included':
                    $included_paths = [];
                    $excluded_paths = [];

                    $action = false;
                    while($action != 'Continue')
                    {
                        $message = [];

                        $message0 = [];
                        if (count($included_paths))
                        {
                            $message0 []= '[included]';
                            foreach ($included_paths as $path => $properties)
                            {
                                $message0 [] = $this->color('blue') .$path. " = \t'" .$properties['dirname']."'";
                            }
                            $message0 []= '';

                            if (count($excluded_paths))
                            {
                                $message0 []= '[excluded]';
                                foreach ($excluded_paths as $path => $excluded_paths)
                                {
                                    $message0 [] = $this->color('blue') .$path. " = \t'" .implode(',', $excluded_paths)."'";
                                }
                                $message0 []= '';
                            }
                        }

                        // add config
                        foreach ($message0 as $m) $message []= $m;

                        $options = [];
                        $options ['Add'] ='Add a new partition or directory';
                        if(count($included_paths))
                        {
                            $options ['Modify']='Modify a partition or directory';
                            $options ['Continue']='Move on to the next section';
                        }
                        // message
                        $message [] = 'What would you like to do?';
                        $action = $this->Dialog->menu(implode("\n", $message), 'included', $options, 'Continue');

                        $message = [];
                        switch($action)
                        {
                            case 'Add':
                                $message []= 'Enter the path:';
                                $path = $this->Dialog->inputbox(implode("\n", $message), $section, '/');

                                // TODO validation
                                //validate


                                // last element as default
                                if ($path == '/')
                                {
                                    $dirname = 'root';
                                }
                                else
                                {
                                    $dirname = end(explode('/', $path));
                                }
                                $included_paths [$path]['dirname'] = $dirname;

                                $message = [];
                                $message []= 'Path: '.$path;
                                $message []= '';
                                $message []= 'Enter the name for this partition or directory.';
                                $dirname = $this->Dialog->inputbox(implode("\n", $message), $section, $dirname);
                                $included_paths [$path]['dirname'] = $dirname;
                                break;
                            case 'Modify':
                                $message [] = 'Select a path you wish to edit.';

                                //items
                                $items = [];
                                foreach($included_paths as $path => $properties)
                                {
                                    $items [$path] = $properties['dirname'];
                                }

                                $old_path = $this->Dialog->menu(implode("\n", $message), $section, $items );

                                $message = ['Modify the path. Leave blank to delete it.'];
                                $path = trim($this->Dialog->inputbox(implode("\n", $message), $section, $old_path));

                                //unset the old path
                                unset($included_paths[$old_path]);

                                if(!empty($path))
                                {
                                    // copy all values to new path
                                    $included_paths[$path] = $included_paths[$old_path];

                                    // last element as default
                                    $dirname = end(explode('/', $path));
                                    $included_paths [$path]['dirname'] = $dirname;

                                    $message = [];
                                    $message []= 'Enter the name for this partition or directory.';
                                    $dirname = $this->Dialog->inputbox(implode("\n", $message), $section, $included_paths[$path]['dirname']);
                                    $included_paths [$path]['dirname'] = $dirname;

                                    $message = [];
                                    $message []= 'Would you like to exclude paths for this partition or directory?';
                                    $add_excluded_path = $this->Dialog->yesno(implode("\n", $message), 'excluded', false);

                                    while($add_excluded_path)
                                    {
                                        $message = [];
                                        $message []= 'Enter a relative path to exclude from this partition or directory.';
                                        $dirname = $this->Dialog->inputbox(implode("\n", $message), 'excluded');
                                        $excluded_paths [$path][] = $dirname;

                                        $message = [];
                                        $message []= 'Would you like to exclude another path for this partition or directory?';
                                        $add_excluded_path = $this->Dialog->yesno(implode("\n", $message), 'excluded', false);
                                    }
                                }
                                break;
                        }
                        // sort on path
                        ksort($included_paths);
                    }
                    break;
                case  'mysql':
                    $message [] = '';
                    $message [] = 'Would you like to configure database backups?';
                    $enable_section = $this->Dialog->yesno(implode("\n", $message), $section, false);

                    if($enable_section)
                    {
                        $message = [];
                        $message [] = 'Per default, all databases and tables are included. Would you like to specify specific databases or tables instead?';
                        $enable_includes = $this->Dialog->yesno(implode("\n", $message), $section, false);

                        if(!$enable_includes)
                        {
                            break;
                        }

                        $included_db = [];

                        $action = false;
                        while($action != 'Continue')
                        {
                            $message = [];

                            $message0 = [];
                            if (count($included_db))
                            {
                                $message0 []= '[mysql]';
                                foreach ($included_db as $directive => $value)
                                {
                                    $message0 [] = $this->color('blue') .$directive. " = \t'" .$value."'";
                                }
                                $message0 []= '';
                            }

                            // add config
                            foreach ($message0 as $m) $message []= $m;

                            $options1 = [
                                'included-databases' => 'Specify databases to include',
                                'excluded-databases' => 'Specify databases to exclude',
                                'included-tables' => 'Specify tables to include',
                                'excluded-tables' => 'Specify tables to exclude',
                                'ignore-tables' => 'Specify tables to ignore'
                            ];

                            $options = $options1;
                            $options ['Continue']='Move on to the next section';
//                            if(count($included_db))
//                            {
////                                $options ['Modify']='Modify a directive';
//                                $options ['Continue']='Move on to the next section';
//                            }
                            // message
                            $message [] = 'What would you like to do?';
                            $action = $this->Dialog->menu(implode("\n", $message), 'included', $options, 'Continue');

                            $message = [];
                            switch($action)
                            {
                                case 'Continue':
                                    // unset directives
                                    foreach($options1 as $option)
                                    {
                                        if(in_array($option, array_keys($included_db)))
                                        {

                                        }
                                        else
                                        {

                                        }
                                    }
                                    foreach($included_db as $index => $value)
                                    {
                                        $this->Config->set($section.'.'.$index, $value);
                                    }
                                    break;
                                default:
                                    //composed index
                                    $composed_index = $section . '.' . $action;

                                    // match periodic backups syntax - use s flag to not stop at newline
                                    $title = str_replace('-', ' ', strtoupper($action));
                                    preg_match('/; '.$title.' *\n(;[^;\n]*\n)+/s', $example_file_as_string, $matches);

                                    $syntax = [];
                                    $syntax []= trim(preg_replace('/; ?'.$action.' ?=.+/', '', $matches[0]));
                                    $syntax []= 'Leave blank to delete the directive.';
                                    $value = $this->Dialog->inputbox(implode("\n", $syntax), $composed_index, $included_db [$action]);

                                    if(empty(trim($value)))
                                    {
                                        unset($included_db [$action]);
                                    }
                                    else
                                    {
                                        $included_db [$action] = $value;
                                    }
                            }
                            // sort on path
                            ksort($included_paths);
                        }
                    }

                    break;
                case  'remote':
                    $message = [];
                    $message [] = $this->get_message('remote-scripts');
                    $remote_scripts = $this->Dialog->yesno(implode("\n", $message), $section . '.scripts', false);
                    break;
                case 'snapshots':
                    $config = [];
                    $config ['incremental'] = 3;
                    $config ['1-daily'] = 7;
                    $config ['1-weekly'] = 4;
                    $config ['1-monthly'] = 6;
                    $config ['1-yearly'] = 1;

                    $snapshot_types = ['incremental', 'minutely', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'];

                    $action = false;
                    while($action != 'Continue')
                    {
                        $message = [];
                        $message0 = [];
                        $message0 []= '[snapshots]';
                        foreach($config as $pattern => $number_of_snapshots_to_keep)
                        {
                            $message0 []= $this->color('blue').$pattern.' = '.$number_of_snapshots_to_keep;
                        }
                        $message0 []= '';
                        // add config
                        foreach ($message0 as $m) $message []= $m;
                        $message [] = 'What would you like to do?';

                        $options = [];
                        $options ['Add']='Add a snapshot directive';
                        if(count($config))
                        {
                            $options ['Modify']='Modify a snapshot directive';
                            $options ['Erase All']='Start from scratch';
                            $options ['Continue']='Move on to the next section';
                        }

                        $action = $this->Dialog->menu(implode("\n", $message), 'snapshots', $options, 'Continue');

                        // match periodic backups syntax - use s flag to not stop at newline
                        $title = 'PERIODIC SNAPSHOTS';
                        preg_match('/; '.$title.' *\n(;[^;\n]*\n)+/s', $example_file_as_string, $matches);
                        $syntax = $matches[0];

                        $message = [];
                        switch($action)
                        {
                            case 'Add':
                                $message = [];
                                $message []= implode("\n", $syntax);
                                $message []= 'Enter a snapshot period:';
                                $period = $this->Dialog->menu(implode("\n", $message), $section, $snapshot_types);

                                // TODO - validate
                                //validate

                                // the offset
                                $offset = '';
                                if ($period != 'incremental')
                                {
                                    $message = [];
                                    $message []= implode("\n", $syntax);
                                    $message []= 'Enter an offset:';
                                    $offset = $this->Dialog->inputbox(implode("\n", $message), $section, 1).'-';
                                }

                                $message = [];
                                $message []= implode("\n", $syntax);
                                $message []= 'Number of snapshots:';
                                $number_of_snapshots = $this->Dialog->inputbox(implode("\n", $message), $section, 3);

                                // TODO save data??
                                $config [$offset.$period] = $number_of_snapshots;

                                break;
                            case 'Erase All':
                                $config = [];
                                break;
                            case 'Modify':
                                $message [] = 'Select a snapshot directive you wish to edit.';
                                $snapshot_directive = $this->Dialog->menu(implode("\n", $message), $section, $config);
                                $default = $config[$snapshot_directive];

                                $message = ['Modify the snapshot directive. Leave blank to delete it.'];
                                $number_of_snapshots = trim($this->Dialog->inputbox(implode("\n", $message), $section, $default));

                                if(empty($number_of_snapshots))
                                {
                                    //unset the old path
                                    unset($config[$snapshot_directive]);
                                }
                                else
                                {
                                    $config[$snapshot_directive] = $number_of_snapshots;
                                }

                                break;
                        }
                        ksort($config);
                    }
                    break;
                default:
                    $this->Dialog->msgbox($this->get_message('[' . $section . ']'), $section);
            }


            // skip section if user agrees to use defaults
            if (!$enable_section)
            {
                continue;
            }

            #####################################
            # DIRECTIVES
            #####################################
            foreach ($this->Config->get($section) as $directive => $default)
            {
                // set the title
                $composed_index = $section . '.' . $directive;

                // override defaults
                switch ($composed_index)
                {
                    case 'mysql.enabled':
                        // if we got this far, skip redundant question. The section is enabled obviously.
                        $this->Config->set('mysql.enabled', true);
                        continue 2;
                        break;
                    case 'mysql.included-databases':
                    case 'mysql.excluded-databases':
                    case 'mysql.included-tables':
                    case 'mysql.excluded-tables':
                    case 'mysql.ignore-tables':
                        continue 2;
                        break;
                    case 'remote.port':
                    case 'remote.retry-count':
                    case 'remote.retry-timeout':
                    case 'remote.host':
                    case 'remote.user':
                        if (!$this->Config->get('remote.ssh'))
                        {
//                             $value = '';
//                             $this->Config->set($composed_index, $value);
                            continue 2;
                        }
                        break;
                    case 'local.hostdir-name':
                        $default = $this->Config->get('remote.host');
                        break;
                    case 'remote.pre-backup-script':
                    case 'remote.pre-backup-onfail':
                    case 'remote.backup-onfail':
                    case 'remote.post-backup-script':
                        if (!$remote_scripts)
                        {
                            continue 2;
                        }
                        break;
                }

                $validated = false;
                $error_messages = [];
                // as long as there are errors, execute loop
                while (!$validated || count($error_messages))
                {
                    $message = $this->get_message($composed_index);

                    // add errors
                    $generated_config = [];
                    $generated_config [] = $message;
                    foreach ($error_messages as $error_message)
                    {
                        $generated_config [] = $this->color('red', 'bold') . $error_message;
                    }

                    $message = implode("\n", $generated_config);

                    if (is_bool($default))
                    {
                        $value = $this->Dialog->yesno($message, $composed_index, $default);
                    }
                    elseif (isset($this->ini_skeleton[$composed_index]['validate']['allowed']))
                    {
                        $value = $this->Dialog->menu($message, $composed_index, $this->ini_skeleton[$composed_index]['validate']['allowed']);
                    }
                    else
                    {
                        $value = $this->Dialog->inputbox($message, $composed_index, $default);
                    }

                    #####################################
                    # VALIDATE
                    #####################################
                    $error_messages = [];

                    if ($value === '')
                    {
                        $error_messages [] = 'Value may not be empty!';
                    }

                    $validated = true;
                    #####################################
                    # STORE
                    #####################################
                    $this->Config->set($composed_index, $value);
                }
            }
        }

        #####################################
        # DISPLAY VALUES
        #####################################
        $generated_config = [];

        // sort by section array
        foreach($sections as $section)
        {
            $all_config[$section] = $this->Config->get($section);
        }

        foreach ($all_config as $section => $directives)
        {
            if (is_array($directives))
            {
                ksort($directives);
                $generated_config [] = "\n[$section]";
                foreach ($directives as $directive => $setting)
                {
                    // check value
                    $index = $section.'.'.$directive;

                    if(is_array($this->ini_skeleton[$index]['validate']))
                    {
                        // boolean
                        if(in_array('boolean', array_keys($this->ini_skeleton[$index]['validate'])))
                        {
                            $setting = ($setting == '1')? 'yes':'no';
                        }
                        // integer
                        elseif(in_array('integer', array_keys($this->ini_skeleton[$index]['validate'])))
                        {
                            $setting = stripslashes($setting);
                        }
                        else
                        {
                            $setting = '"'.$setting.'"';
                        }
                    }
                    else
                    {
                        $setting = '"'.$setting.'"';
                    }

                    $generated_config [] = sprintf("%s = %s", $directive, $setting);
                }
            }
            else
            {
                $directives = ($directives) ? $directives : '""';
                $generated_config [] = sprintf("%s = %s", $section, $directives);
            }
        }

        $message = [];
        $message []= trim(implode("\n", $generated_config));
//        $message []= '';
//        $message []= 'Do you accept this config?';

        $accepted = $this->Dialog->yesno(implode("\n", $message), 'config file', true, ['yes'=>'Continue', 'no'=>'Abort']);

        if (!$accepted)
        {
            die();
        }
        #####################################
        # WRITE CONFIG
        #####################################
        $file_path = '~/poppins.d/config/'.$this->Config->get('remote.host').'.poppins.ini';
        $file_path = $this->Dialog->inputbox('Config file will be written. Please provide a path:', 'output', $file_path);

        $file_contents = [];

        $session = '';
        $session_trimmed = '';
        $directive = '';

        //            var_dump($this->documentation);
        //            die();

        foreach($generated_config as $line)
        {

            $line = trim($line);

            if(empty($line))
            {
                continue;
            }

            // match [xyz]
            preg_match('/^\[.+\]$/', $line, $matches);

            // session
            if(count($matches))
            {
                $session = $matches[0];
                $session_trimmed = trim($matches[0], '[]');
                $documentation = $this->get_message($session, 'config');
            }
            // directive
            else
            {
                preg_match('/^[^ ]+/', $line, $matches);
                $directive = trim($matches[0]);

                // get documentation
                $documentation = $this->get_message($session_trimmed.'.'.$directive, 'config');
            }

            if($documentation)
            {
                $string = preg_replace('/\n$/','', $documentation);
                $string = preg_replace('/^\n/','', $string);
                $file_contents []= $string;
                //                    $file_contents []= ';'.implode(";", explode($documentation, "\n"));
            }
            else
            {
                //die("\nNO DOC FOR $o \n");
            }
            $file_contents []= "$line";

        }

        $out = [];

        // add space
        foreach($file_contents as $line)
        {
            if (preg_match('/^;;/', $line))
            {
                //                    continue;
            }

            $out []= $line;

            if (!preg_match('/^;/', $line))
            {
                $out []= '';
            }

        }

        // write the file
        file_put_contents($file_path,  trim(implode("\n", $out)));

        // last dialog
        $this->Dialog->msgbox('Installation complete...');
        $this->quit();

    }

    function color($color = false, $weight = 'normal')
    {
        return $this->Dialog->color($color, $weight);
    }

    function fail()
    {
        echo 'failed!';
        $this->quit();
    }

    function get_message($subject, $output = 'dialog')
    {
        // allow for paragraphs
        $message = [];

        if(isset($this->documentation[$subject]))
        {
            foreach($this->documentation[$subject] as $line)
            {
                if($output == 'dialog')
                {
                    if(empty($line) || preg_match('/^;;/', $line) || preg_match('/^; *([A-Z] ?)+$/', $line))
                    {
                        continue;
                    }
                    else
                    {
                        $message[]= trim($line, '; ');
                    }

                }
                else
                {
                    $message[]= $line;
                }
            }
            $message []= '';
        }

        switch($subject)
        {
            case 'poppins-intro':
                $message []= $this->color('black', 'bold').'A config file will be generated. Do you want to continue?';
                break;
            case 'remote-scripts':
                $message []= 'You can run a script on the remote host before or after every backup run. Would you like to configure these scripts?';
                break;
           }

        return implode("\n", $message);
    }

    function quit()
    {
        system('clear');
        die("Goodbye!");
    }

}
