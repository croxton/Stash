<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once PATH_THIRD . 'stash/config.php';

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2019 Hallmark Design
 * @link                http://hallmark-design.co.uk
 */

class Stash_ext {

    public $name            = STASH_NAME;
    public $version         = STASH_VER;
    public $description     = STASH_DESC;
    public $docs_url        = STASH_DOCS;
    public $settings        = array();
    public $settings_exist  = 'n';

    // ------------------------------------------------------

    /**
     * Constructor
     *
     * @param   mixed   Settings array or empty string if none exist
     * @return void
     */
    public function __construct($settings = array())
    {
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
        $this->_add_hook('stash_fetch_template', 10);
        $this->_add_hook('stash_post_parse', 1);
        $this->_add_hook('template_fetch_template', 10);
        $this->_add_hook('template_post_parse', 1);
    }
    
    // ------------------------------------------------------

    /**
     * Disable Extension
     *
     * @return void
     */
    public function disable_extension()
    {
        ee()->db->where('class', __CLASS__);
        ee()->db->delete('extensions');
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

        // Update to 2.6.5
        if (version_compare($current, '2.6.5', '<'))
        {
            // change existing 'template_post_parse' extension priority to highest
            ee()->db->where('class', __CLASS__);
            ee()->db->where('hook', 'template_post_parse');
            ee()->db->update('extensions', array('priority' => 1));

            // add new hooks
            $this->_add_hook('stash_fetch_template', 10);
            $this->_add_hook('stash_post_parse', 1);
        }

        // update table row with current version
        ee()->db->where('class', __CLASS__);
        ee()->db->update('extensions', array('version' => $this->version));
    }
    
    // --------------------------------------------------------------------

    /**
     * Add extension hook
     *
     * @access     private
     * @param      string
     * @param      integer
     * @return     void
     */
    private function _add_hook($name, $priority = 10)
    {
        ee()->db->insert('extensions',
            array(
                'class'    => __CLASS__,
                'method'   => $name,
                'hook'     => $name,
                'settings' => '',
                'priority' => $priority,
                'version'  => $this->version,
                'enabled'  => 'y'
            )
        );
    }
    
    // ------------------------------------------------------
    // 
    /**
     * Method for stash_fetch_template hook
     *
     * Inject early stash embeds into the template
     *
     * @access     public
     * @param      array
     * @return     array
     */
    public function stash_fetch_template($row)
    {
        return $this->template_fetch_template($row);
    }
    
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
        if (isset(ee()->extensions->last_call) && ee()->extensions->last_call)
        {
            $row = ee()->extensions->last_call;
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
                $matches[0][$key] = ee()->functions->full_tag($matches[0][$key], $temp);
                $matches[2][$key] = substr(str_replace($matches[1][$key], '', $matches[0][$key]), 0, -1);
                $temp = str_replace($matches[0][$key], '', $temp);
            }
        }
        
        // match up embed params with tags
        $embeds = array();
    
        foreach($matches[2] as $key => $val)
        {
            $parts = preg_split("/\s+/", $val, 2);
            
            $embed_params = (isset($parts[1])) ? ee()->functions->assign_parameters($parts[1]) : array();

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
                        if (count(ee()->TMPL->modules) == 0)
                        {
                            ee()->TMPL->fetch_addons();
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
                            if ( ! array_key_exists('stash', ee()->session->cache) )
                            {
                                ee()->session->cache['stash'] = array();
                            }   
                            ee()->session->cache['stash'] = array_merge(ee()->session->cache['stash'], $embed_vars);
                        }

                        // instantiate Stash without initialising
                        $s = new Stash(TRUE);

                        // get the file
                        $out = $s->get($param);
                        unset($s);

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
                ee()->config->_global_vars = array($tag => $out) + ee()->config->_global_vars;
            }
        }

        return $row;
    }
    
    // ------------------------------------------------------
    // 
    /**
     * Method for stash_post_parse hook
     *
     * @param   string  Parsed template string
     * @param   bool    Whether an embed or not
     * @param   integer Site ID
     * @return  string  Template string
     */
    public function stash_post_parse($template, $sub, $site_id)
    { 
        return $this->template_post_parse($template, $sub, $site_id, TRUE, FALSE);
    }

    /**
     * Method for template_post_parse hook
     *
     * @param   string  Parsed template string
     * @param   bool    Whether an embed or not
     * @param   integer Site ID
     * @param   bool    Has the extension been called by Stash rather than EE?
     * @param   bool    Final call of this extension
     * @return  string  Template string
     */
    public function template_post_parse($template, $sub, $site_id, $from_stash = FALSE, $final = FALSE)
    {   
        // play nice with other extensions on this hook
        if (isset(ee()->extensions->last_call) && ee()->extensions->last_call)
        {
            $template = ee()->extensions->last_call;
        }

        // is this the final template?
        if ($sub == FALSE && $final == FALSE)
        {   
            // check the cache for postponed tags
            if ( ! isset(ee()->session->cache['stash']['__template_post_parse__']))
            {
                ee()->session->cache['stash']['__template_post_parse__'] = array();
            }

            // an array of tags needing to be post-parsed
            $cache = ee()->session->cache['stash']['__template_post_parse__'];

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
                $tagparams  = isset(ee()->TMPL->tagparams) ? ee()->TMPL->tagparams : array();
                $tagdata    = isset(ee()->TMPL->tagdata)   ? ee()->TMPL->tagdata : '';

                // reset tagparams so Stash is instantiated with default values
                ee()->TMPL->tagparams = array();

                // instantiate but don't initialise
                $s = new Stash(TRUE);

                // sort by priority
                $cache = $s->sort_by_key($cache, 'priority', 'sort_by_integer');

                // loop through, prep the Stash instance, call the postponed tag and replace output into the placeholder
                foreach($cache as $placeholder => $tag)
                {   
                    if ( strpos( $template, $placeholder ) !== FALSE)
                    {
                        // make sure there is a placeholder in the template
                        // it may have been removed by advanced conditional processing
                        ee()->TMPL->log_item("Stash: post-processing tag: " . $tag['tagproper'] . " will be replaced into " . LD . $placeholder . RD);
                        
                        ee()->TMPL->tagparams = $tag['tagparams'];
                        ee()->TMPL->tagdata = $tag['tagdata'];
                        
                        // restore context @ pointer in context parameter
                        if (isset(ee()->TMPL->tagparams['context']) && ee()->TMPL->tagparams['context'] == '@')
                        {
                            ee()->TMPL->tagparams['context'] = $context;
                        }
                        
                        // restore context @ pointer if hardcoded in name parameter
                        if (isset(ee()->TMPL->tagparams['name']) 
                            && strncmp(ee()->TMPL->tagparams['name'], '@:', 2) == 0)
                        {
                            ee()->TMPL->tagparams['name'] = str_replace('@', $context, ee()->TMPL->tagparams['name']);
                        }
                        
                        // restore context @ pointer if hardcoded in file_name parameter
                        if (isset(ee()->TMPL->tagparams['file_name']) 
                            && strncmp(ee()->TMPL->tagparams['file_name'], '@:', 2) == 0)
                        {
                            ee()->TMPL->tagparams['file_name'] = str_replace('@', $context, ee()->TMPL->tagparams['file_name']);
                        }

                        // initialise Stash with our custom tagparams
                        $s->init(TRUE);

                        // has the save_output or final_output tags been called?
                        if ( $tag['method'] === 'save_output' || $tag['method'] === 'final_output')
                        {   
                            // remove placeholder from the template
                            $template = str_replace(LD.$placeholder.RD, '', $template);  

                            // allow the called method to alter/cache the entire template
                            $template = $s->{$tag['method']}($template);
                        }
                        else
                        {
                            // call the tag
                            $out = $s->{$tag['method']}();
                            
                            // replace the output of our tag into the template placeholder
                            $template = str_replace(LD.$placeholder.RD, $out, $template);    
                        }

                        // remove the placeholder from the cache so we don't iterate over it in future calls of this hook
                        unset(ee()->session->cache['stash']['__template_post_parse__'][$placeholder]);
                    }
                }
                
                // restore original TMPL values
                ee()->TMPL->tagparams = $tagparams;
                ee()->TMPL->tagdata = $tagdata;
            }

            // cleanup
            unset($cache);

            // just before the template is sent to output
            if (FALSE == $from_stash)
            {
                // batch processing of cached variables
                ee()->load->model('stash_model');

                // get the query queue by reference
                $queue = &ee()->stash_model->get_queue();
                
                // we need to flatten the data in the queue to a string, so we can parse it
                // first, let's extract the data into a simple indexed array...
                $data = array();

                foreach($queue->inserts as $table => $inserts)
                {
                    foreach($inserts as $query)
                    {
                        $data[] = $query['parameters']; // will always exist
                    }
                }

                foreach($queue->updates as $table => $updates)
                {
                    foreach($updates as $query)
                    {
                        if (isset($query['parameters']))
                        {
                            $data[] = $query['parameters'];
                        }
                    }
                }

                if ( count($data) > 0 )
                {
                    // flatten data so we can parse it
                    $delim = '|' . ee()->functions->random() . '|';
                    $data = (string) implode($delim, $data);

                    // Run template_post_parse on the flattened data.
                    // We need to disable the in_progress recursion check in EE_Extensions::universal_call
                    // don't even think about making this private, @pkriete !  
                    ee()->extensions->in_progress = '';
                    $data = ee()->extensions->call(
                        'template_post_parse',
                        $data,
                        FALSE, 
                        ee()->config->item('site_id'), 
                        TRUE,
                        TRUE // prevent recursion of this method
                    );
                    ee()->extensions->in_progress = 'template_post_parse'; // restore recursion check

                    // explode the data back into an array
                    $data = (array) explode($delim, $data);

                    // update the queues with the parsed parameter values
                    foreach($queue->inserts as $table => $inserts)
                    {
                        foreach($inserts as $cache_key => $query)
                        {
                           $queue->inserts[$table][$cache_key]['parameters'] = array_shift($data);
                        }
                    }

                    foreach($queue->updates as $table => $updates)
                    {
                        foreach($updates as $cache_key => $query)
                        {
                            if (isset($query['parameters']))
                            {
                                $queue->updates[$table][$cache_key]['parameters'] = array_shift($data);
                            }
                        }
                    }

                    unset($data);
                }

                // process inserts/updates queue
                ee()->TMPL->log_item("Stash: batch processing queued queries");
                ee()->stash_model->process_queue();
            }
        }
        
        return $template;
    }
}

/* End of file ext.stash.php */ 
/* Location: ./system/expressionengine/third_party/stash/ext.stash.php */