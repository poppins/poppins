<?php

//singelton
class Store
{

    protected $stored;

    function __construct()
    {
        $this->stored = [];
    }

    //singleton
    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance)
        {
            $instance = new static();
        }

        return $instance;
    }

    public function store($setting = [])
    {
        $this->stored = array_replace_recursive($this->stored, $setting);
    }

    public function get($index = false, $default = '')
    {
        //get all values
        if(!$index)
        {
            ksort($this->stored);
            return $this->stored;
        }
        //array
        elseif(is_array($index))
        {
            return $this->get_key_based($index, $default);
        }
        //string
        else
        {
            return $this->get_path_based($index, $default);
        }
    }

    //array notation
    public function get_key_based($keys, $default = '')
    {
        $i = 1;
        $c = count($keys);

        $tmp = $this->stored;

        foreach ($keys as $k)
        {
            if($i == $c)
            {
                if(isset($tmp[$k]))
                {
                    return $tmp[$k];
                }
                else
                {
                    return $default;
                }
            }
            else
            {
                $tmp = $tmp[$k];
            }
            $i++;
        }
    }

    //dot notation
    public function get_path_based($path, $default = '')
    {
        //if no dotes, return index
        if(!preg_match('/\./', $path))
        {
            return $this->stored[$path];
        }

        $current = $this->stored;
        $p = strtok($path, '.');

        while ($p !== false)
        {
            if (!isset($current[$p]))
            {
                return $default;
            }
            $current = $current[$p];
            $p = strtok('.');
        }
        return $current;
    }

    // check if value is set
    public function is_set($index)
    {
        //array
        if(is_array($index))
        {
            return $this->is_set_key_based($index);
        }
        //string
        else
        {
            return $this->is_set_path_based($index);
        }
    }

    //TODO remove code dupication
    //array notation
    public function is_set_key_based($keys, $default = '')
    {
        $i = 1;
        $c = count($keys);

        $tmp = $this->stored;

        foreach ($keys as $k)
        {
            if($i == $c)
            {
                return (isset($tmp[$k]));
            }
            else
            {
                $tmp = $tmp[$k];
            }
            $i++;
        }
    }

    //TODO remove code dupication
    //dot notation
    public function is_set_path_based($path)
    {
        //if no dotes, return index
        if(!preg_match('/\./', $path))
        {
            return isset($this->stored[$path]);
        }

        // dots
        $current = $this->stored;
        $p = strtok($path, '.');

        while ($p !== false)
        {
            if (!isset($current[$p]))
            {
                return false;
            }
            $current = $current[$p];
            $p = strtok('.');
        }
        return isset($current);
    }

    /*
     * mixed index
     */
    public function set($index, $value)
    {
        if(is_array($index))
        {
            $this->set_key_based($index, $value);
        }
        else
        {
            $this->set_path_based($index, $value);
        }
    }

    private function set_path_based($path, $value)
    {
        $keys = explode('.', $path);
        return $this->set_key_based($keys, $value);
    }

    private function set_key_based($keys, $value)
    {
        $res = array();
        $tmp =& $res;

        foreach ($keys as $k)
        {
            $tmp[$k] = array();
            $tmp =& $tmp[$k];
        }

        $tmp = $value;

        $this->store($res);
    }

}
