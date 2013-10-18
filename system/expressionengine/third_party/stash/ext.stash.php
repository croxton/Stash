<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once PATH_THIRD . 'stash/config.php';

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2012 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash_ext {

    public $EE;
    public $name            = STASH_NAME;
    public $version         = STASH_VER;
    public $description     = STASH_DESC;
    public $docs_url        = STASH_DOCS;
    public $settings        = array();
    public $settings_exist  = 'n';
    private $hooks          = array('template_fetch_template', 'template_post_parse');

    // ------------------------------------------------------

    /**
     * Constructor
     *
     * @param   mixed   Settings array or empty string if none exist
     * @return void
     */
    public function __construct($settings = array())
    {
        $this->EE =& get_instance();
        $this->settings = $settings;
    }

    // ------------------------------------------------------

    /**
     * Activate Extension
     * 
     * @return void
     */
    public function activate_extension()
    {
        foreach ($this->hooks AS $hook)
        {
            $this->_add_hook($hook);
        }
    }
    
    // ------------------------------------------------------

    /**
     * Disable Extension
     *
     * @return void
     */
    public function disable_extension()
    {
        $this->EE->db->where('class', __CLASS__);
        $this->EE->db->delete('extensions');
    }
    
    // ------------------------------------------------------

    /**
     * Update Extension
     *
     * @param   string  String value of current version
     * @return  mixed   void on update / FALSE if none
     */
    public function update_extension($current = '')
    {
        if ($current == '' OR (version_compare($current, $this->version) === 0))
        {
            return FALSE; // up to date
        }

        // update table row with current version
        $this->EE->db->where('class', __CLASS__);
        $this->EE->db->update('extensions', array('version' => $this->version));
    }
    
    // --------------------------------------------------------------------

    /**
     * Add extension hook
     *
     * @access     private
     * @param      string
     * @return     void
     */
    private function _add_hook($name)
    {
        $this->EE->db->insert('extensions',
            array(
                'class'    => __CLASS__,
                'method'   => $name,
                'hook'     => $name,
                'settings' => '',
                'priority' => 10,
                'version'  => $this->version,
                'enabled'  => 'y'
            )
        );
    }
    
    // ------------------------------------------------------
    
    /**
     * Method for template_fetch_template hook
     *
     * Inject early stash embeds into the template
     *
     * @access     public
     * @param      array
     * @return     array
     */
    public function template_fetch_template($row)
    {       
        // get the latest version of $row
        if (isset($this->EE->extensions->last_call) && $this->EE->extensions->last_call)
        {
            $row = $this->EE->extensions->last_call;
        }   
        
        // do we have any stash embeds? {stash:embed name=""} or {stash:embed:name}
        $matches = array();
        if ( ! preg_match_all("/(".LD."stash:embed)([\s|:].*?)".RD."/s", $row['template_data'], $matches))
        {
            return $row;
        }
        
        // deal with any unparsed {vars} inside parameters
        $temp = $row['template_data'];
        
        foreach ($matches[2] as $key => $val)
        {
            if (strpos($val, LD) !== FALSE)
            {
                $matches[0][$key] = $this->EE->functions->full_tag($matches[0][$key], $temp);
                $matches[2][$key] = substr(str_replace($matches[1][$key], '', $matches[0][$key]), 0, -1);
                $temp = str_replace($matches[0][$key], '', $temp);
            }
        }
        
        // match up embed params with tags
        $embeds = array();
    
        foreach($matches[2] as $key => $val)
        {
            $parts = preg_split("/\s+/", $val, 2);
            
            $embed_params = (isset($parts[1])) ? $this->EE->functions->assign_parameters($parts[1]) : array();

            if ($embed_params === FALSE)
            {
                $embed_params = array();
            }
            
            // support {stash:embed:context:var}
            if ( ! empty($parts[0]))
            {
                $embed_params['name'] = trim($parts[0], ':');
            }
    
            $embeds[trim($matches[0][$key], LD.RD)] = $embed_params;
        }

        if (count($embeds) > 0)
        {
            if ( ! class_exists('Stash'))
            {
                include_once PATH_THIRD . 'stash/mod.stash.php';
            }
            
            foreach($embeds as $tag => $param)
            {
                $out = '';
                
                if ( ! empty($param))
                {   
                    // process early?
                    if ( isset($param['process']) && $param['process'] == 'start')
                    {
                        // mandatory parameters
                        $param['scope']     = 'site';
                        $param['file']      = 'yes';
                        $param['save']      = 'yes';
                        $param['bundle']    = 'template';
                        #$param['process']  = 'inline';
                        
                        // set default parse parameters
                        $param['parse_tags']         = isset($param['parse_tags']) ? $param['parse_tags'] : 'yes';
                        $param['parse_vars']         = isset($param['parse_vars']) ? $param['parse_vars'] : 'yes';
                        $param['parse_conditionals'] = isset($param['parse_conditionals']) ? $param['parse_conditionals'] : 'yes';

                        // parse="yes"?
                        if (isset($param['parse']))
                        {
                            if ( (bool) preg_match('/1|on|yes|y/i', $param['parse']))
                            {
                                // parse="yes"
                                $param['parse_tags']          = 'yes';
                                $param['parse_vars']          = 'yes';
                                $param['parse_conditionals']  = 'yes';
                            }
                            elseif ( (bool) preg_match('/^(0|off|no|n)$/i', $param['parse']))
                            {
                                // parse="no"
                                $param['parse_tags']          = 'no';
                                $param['parse_vars']          = 'no';
                                $param['parse_conditionals']  = 'no';
                            }
                        }

                        $param['parse_depth']        = isset($param['parse_depth']) ? $param['parse_depth'] : 4;
                        $param['parse_stage']        = isset($param['parse_stage']) ? $param['parse_stage'] : 'get';
                        $param['replace']            = isset($param['replace']) ? $param['replace'] : 'no';

                        // We need to load modules/plugins, which hasn't been done by the template class at this stage in the parse order.
                        // This only runs once - the template class won't run it again if modules[] array is populated.
                        if (count($this->EE->TMPL->modules) == 0)
                        {
                            $this->EE->TMPL->fetch_addons();
                        }
                        
                        // parse stash embed vars passed as parameters in the form stash:my_var
                        $embed_vars = array();

                        foreach ($param as $key => $val)
                        {
                            if (strncmp($key, 'stash:', 6) == 0)
                            {
                                $embed_vars[substr($key, 6)] = $val;
                            }
                        }

                        // merge embed variables into the session cache in case they are used in another nested (or later-parsed) stash template
                        if ( ! empty($embed_vars))
                        {
                            // create a stash array in the session if we don't have one
                            if ( ! array_key_exists('stash', $this->EE->session->cache) )
                            {
                                $this->EE->session->cache['stash'] = array();
                            }   
                            $this->EE->session->cache['stash'] = array_merge($this->EE->session->cache['stash'], $embed_vars);
                        }

                        // get the file
                        $out = Stash::get($param);

                        // minimal replace of embed vars if we're not using Stash to parse the template variables
                        if ($param['parse_vars'] == 'no')
                        {
                            foreach ($embed_vars as $key => $val)
                            {
                                $out = str_replace(LD.'stash:'.$key.RD, $val, $out);
                            }
                        }

                        // convert any nested {stash:embed} into {exp:stash:embed} tags
                        $out = str_replace(LD.'stash:embed', LD.'exp:stash:embed', $out);
                    }
                    else
                    {       
                        // convert it into a normal tag...
                        $out = LD.'exp:'.$tag.RD;
                    }
                }
                    
                // Set as a global variable so it gets replaced into the template early by the Template class
                // $row is a copy not a reference, so this is the only way to change stuff in the actual template!
                // Make sure the stash embeds are prepended to beginning of the globals array so that any global vars
                // passed as parameters get parsed later by EE 
                $this->EE->config->_global_vars = array($tag => $out) + $this->EE->config->_global_vars;
            }
        }

        return $row;
    }
    
    // ------------------------------------------------------

    /**
     * Method for template_post_parse hook
     *
     * @param   string  Parsed template string
     * @param   bool    Whether an embed or not
     * @param   integer Site ID
     * @return  string  Template string
     */
    public function template_post_parse($template, $sub, $site_id)
    {   
        // play nice with other extensions on this hook
        if (isset($this->EE->extensions->last_call) && $this->EE->extensions->last_call)
        {
            $template = $this->EE->extensions->last_call;
        }

        // is this the final template?
        if ($sub == FALSE)
        {   
            // check the cache for postponed tags
            if ( ! isset($this->EE->session->cache['stash']['__template_post_parse__']))
            {
                $this->EE->session->cache['stash']['__template_post_parse__'] = array();
            }

            // an array of tags needing to be post-parsed
            $cache = $this->EE->session->cache['stash']['__template_post_parse__'];

            // are we capturing the final output of the rendered EE host template?
            $save_output = FALSE;
        
            // run any postponed stash tags
            if ( ! empty($cache))
            {   
                $context = '';
                
                if ( ! class_exists('Stash'))
                {
                    include_once PATH_THIRD . 'stash/mod.stash.php';
                }
                else
                {
                    // get static context if it has been set
                    $context = Stash::$context;
                }

                // save TMPL values for later
                $tagparams  = isset($this->EE->TMPL->tagparams) ? $this->EE->TMPL->tagparams : array();
                $tagdata    = isset($this->EE->TMPL->tagdata)   ? $this->EE->TMPL->tagdata : '';

                // reset tagparams so Stash is instantiated with default values
                $this->EE->TMPL->tagparams = array();

                // instantiate but don't initialise
                $s = new Stash(TRUE);

                // sort by priority
                $cache = $s->sort_by_key($cache, 'priority', 'sort_by_integer');

                // loop through, prep the Stash instance, call the postponed tag and replace output into the placeholder
                foreach($cache as $placeholder => $tag)
                {   
                    // make sure there is a placeholder in the template
                    // it may have been removed by advanced conditional processing
                    if ( strpos( $template, $placeholder ) !== FALSE)
                    {
                        $this->EE->TMPL->log_item("Stash: post-processing tag: " . $tag['tagproper'] . " will be replaced into " . LD . $placeholder . RD);
                        
                        $this->EE->TMPL->tagparams = $tag['tagparams'];
                        $this->EE->TMPL->tagdata = $tag['tagdata'];
                        
                        // restore context @ pointer in context parameter
                        if (isset($this->EE->TMPL->tagparams['context']) && $this->EE->TMPL->tagparams['context'] == '@')
                        {
                            $this->EE->TMPL->tagparams['context'] = $context;
                        }
                        
                        // restore context @ pointer if hardcoded in name parameter
                        if (isset($this->EE->TMPL->tagparams['name']) 
                            && strncmp($this->EE->TMPL->tagparams['name'], '@:', 2) == 0)
                        {
                            $this->EE->TMPL->tagparams['name'] = str_replace('@', $context, $this->EE->TMPL->tagparams['name']);
                        }
                        
                        // restore context @ pointer if hardcoded in file_name parameter
                        if (isset($this->EE->TMPL->tagparams['file_name']) 
                            && strncmp($this->EE->TMPL->tagparams['file_name'], '@:', 2) == 0)
                        {
                            $this->EE->TMPL->tagparams['file_name'] = str_replace('@', $context, $this->EE->TMPL->tagparams['file_name']);
                        }

                        // has the save_output tag been called?
                        if ( $tag['method'] === 'save_output')
                        {
                            $save_output = $tag;
                            $save_output['placeholder'] = $placeholder;
                        }
                        else
                        {
                            // initialise Stash with our custom tagparams
                            $s->init(TRUE);
                    
                            $out = $s->{$tag['method']}();
                    
                            $template = str_replace(LD.$placeholder.RD, $out, $template);   
                    
                            // remove the placeholder from the cache so we don't iterate over it in future calls of this hook
                            unset($this->EE->session->cache['stash']['__template_post_parse__'][$placeholder]);
                        }
                    }
                }
                
                // restore original TMPL values
                $this->EE->TMPL->tagparams = $tagparams;
                $this->EE->TMPL->tagdata = $tagdata;
            }

            // cache output to a static file
            if($save_output)
            {
                $this->EE->TMPL->tagparams = $save_output['tagparams'];
                $s->init(TRUE);
                $template = str_replace(LD.$save_output['placeholder'].RD, '', $template);  
                $s->{$save_output['method']}($template);

                // restore original TMPL values
                $this->EE->TMPL->tagparams = $tagparams;
            }

            // cleanup
            unset($cache);
        }
        
        return $template;
    }
}

/* End of file ext.stash.php */ 
/* Location: ./system/expressionengine/third_party/stash/ext.stash.php */