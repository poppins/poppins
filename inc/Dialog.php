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
class Dialog
{
    function __construct($Cmd)
    {
        $this->dialog_cmd = 'dialog --stdout --backtitle "Poppins Config Generator" --colors';

        $this->dialog_width = 80;

        $this->Cmd = $Cmd;
    }

    public function yesno($message, $title = '', $default = true, $labels = ['yes'=>'Yes', 'no'=>'No'])
    {
        $message = $this->parse_message($message);
        $height = $this->calc_height($message, 'yesno');
        $title = (empty($title))? '':'--title "'.$title.'"';
        $default = ($default)? '--default-button yes':'--default-button no';
        // lables
        $yesno_labels = '';
        foreach($labels as $k => $v)
        {
            $yesno_labels .='--'.$k.'-label '.$v.' ';
        }

        $value = $this->Cmd->exe($this->dialog_cmd.' '.$title.' '.$yesno_labels.' '.$default.' --yesno "'.$message.'" '.$height.' '.$this->dialog_width.' && echo -n YES || echo -n NO');
        # var_dump($value);
        # die('value='.$value
        return ($value == 'YES')? true:false;
    }

    //dialog --title "name" --inputbox "Put your name:" 0 0
    public function inputbox($message, $title = '', $default = '')
    {
        $message = $this->parse_message($message);
        $height = $this->calc_height($message);
        $title = (empty($title))? '':'--title "'.$title.'"';
        $value = $this->Cmd->exe($this->dialog_cmd.' '.$title.' --inputbox "'.$message.'" '.$height.' '.$this->dialog_width.' '.$default);
        return $value;
    }

    // dialog --stdout --menu "Color:" 10 30 3 1 red 2 green 3 blue
    public function menu($message, $title = '', $options, $default = '')
    {
        $message = $this->parse_message($message);
        $height = $this->calc_height($message, 'menu');
        $title = (empty($title))? '':'--title "'.$title.'"';

        //default
        $default = (empty($default))? '':'--default-item "'.$default.'"';

        $options_string = '';
        foreach($options as $k => $v)
        {
            if(is_int($k))
            {
                // do not set any message with the option
                $options_string .= "\"$v\" \"\" ";
            }
            else
            {
                $options_string .= "\"$k\" \"$v\" ";
            }
        }

        $value = $this->Cmd->exe($this->dialog_cmd.' '.$title.' '.$default.' --menu "'.$message.'" '.$height.' '.$this->dialog_width.' 0 '.$options_string);
        return $value;
    }

    // dialog --title "Title" --msgbox "Hello World" 0 0
    public function msgbox($message, $title = '')
    {
        $message = $this->parse_message($message);
        $height = $this->calc_height($message);
        $title = (empty($title))? '':'--title "'.$title.'"';
        $value = $this->Cmd->exe($this->dialog_cmd.' '.$title.' --msgbox "'.$message.'" '.$height.' '.$this->dialog_width);
        return $value;
    }



    private function calc_height($message, $type = 'msgbox')
    {
        switch($type)
        {
            case 'yesno':
                $add_space = 5;
                break;
            case 'menu':
                $add_space = 10;
                break;
            default:
                $add_space = 5;
        }

        // count newlines
        $newlines = substr_count($message, "\n");

        // wordwrap the text with a special character. Next, count the characters so we know how many lines there are
        $wrapped = substr_count(wordwrap($message, $this->dialog_width, "%"), '%')+$add_space;

        // height
        $height = $newlines + $wrapped;

        // maximum height
        $max = 40;
        return ($height > $max)? $max:$height;
    }

    private function parse_message($message, $type = 'default')
    {

        $messages = explode("\n", $message);
        //    --colors
        //    Interpret embedded "\Z" sequences in the dialog text by the following character, which tells dialog to set colors or video attributes:
        //    0 through 7 are the ANSI color numbers used in curses: black, red, green, yellow, blue, magenta, cyan and white respectively.
        //    Bold is set by 'b', reset by 'B'.
        //    Reverse is set by 'r', reset by 'R'.
        //    Underline is set by 'u', reset by 'U'.
        //    The settings are cumulative, e.g., "\Zb\Z1" makes the following text bold (perhaps bright) red.
        //    Restore normal settings with "\Zn".
        $i = 0;
        foreach($messages as $message)
        {
            // remove semicolons
            $message = preg_replace('/; ?/', '', $message);

            if(preg_match('/^\$/', $message) || preg_match('/^e\.g\. /', $message))
            {
                $messages[$i] = $this->color('magenta').$message;
            }
            else
            {
                $messages[$i] = '\Zn'.$message;
            }
            $i++;
        }

        // add some space
        return "\n".implode("\n", $messages);
    }

    public function color($color = false, $weight = 'normal')
    {
        if(!$color)
        {
            return '\Zn';
        }

        $colors = ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white'];

        // get the key
        $color_code = '\Z'.array_search($color, $colors); // $key = 2;

        if($weight == 'bold')
        {
            $color_code .= '\Zb';
        }

        return $color_code;

    }
}