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

class Stash {

    public $version = STASH_VER;
    public $site_id;
    public $path;
    public $file_sync;
    public $stash_cookie;
    public $stash_cookie_expire;
    public $default_scope;
    public $limit_bots;
    public static $context = NULL;
    
    protected $xss_clean;
    protected $replace;
    protected $type;
    protected $parse_tags = FALSE;
    protected $parse_vars = NULL;
    protected $parse_conditionals = FALSE;
    protected $parse_depth = 1;
    protected $parse_complete = FALSE;
    protected $bundle_id = 1;
    protected $process = 'inline';
    protected $priority = 1;
    protected static $bundles = array();
    
    private $_update = FALSE;
    private $_append = TRUE;
    private $_stash;
    private $_session_id;
    private $_ph = array();
    private $_list_delimiter = '|+|';
    private $_list_row_delimiter = '|&|';
    private $_list_row_glue = '|=|';
    private $_list_null = '__NULL__';
    private $_embed_nested = FALSE;
    private $_nocache_suffix = ':nocache';

    private static $_nocache = TRUE;
    private static $_nocache_prefixes = array('stash');
    private static $_is_human = TRUE;
    private static $_cache;

    /*
     * Constructor
     */
    public function __construct($calling_from_hook = FALSE)
    {
        // load dependencies - make sure the package path is available in case the class is being called statically
        ee()->load->add_package_path(PATH_THIRD.'stash/', TRUE);
        ee()->lang->loadfile('stash');
        ee()->load->model('stash_model');

        // default site id
        $this->site_id = ee()->config->item('site_id');
        
        // config defaults
        $this->path                 = ee()->config->item('stash_file_basepath') ? ee()->config->item('stash_file_basepath') : APPPATH . 'stash/';
        $this->file_sync            = $this->_get_boolean_config_item('stash_file_sync', FALSE); // default = FALSE
        $this->stash_cookie         = ee()->config->item('stash_cookie') ? ee()->config->item('stash_cookie') : 'stashid';
        $this->stash_cookie_expire  = ee()->config->item('stash_cookie_expire') ? ee()->config->item('stash_cookie_expire') : 0;
        $this->stash_cookie_enabled = $this->_get_boolean_config_item('stash_cookie_enabled'); // default = TRUE
        $this->default_scope        = ee()->config->item('stash_default_scope') ? ee()->config->item('stash_default_scope') : 'user';
        $this->default_refresh      = ee()->config->item('stash_default_refresh') ? ee()->config->item('stash_default_refresh') : 0; // minutes
        $this->limit_bots           = $this->_get_boolean_config_item('stash_limit_bots', FALSE); // default = FALSE

        // cache pruning can cache stampede mitigation defaults
        $this->prune                = $this->_get_boolean_config_item('stash_prune_enabled'); // default = TRUE
        $this->prune_probability    = ee()->config->item('stash_prune_probability')   ? ee()->config->item('stash_prune_probability') : .4; // percent
        $this->invalidation_period  = ee()->config->item('stash_invalidation_period') ? ee()->config->item('stash_invalidation_period') : 0; // seconds

        // permitted file extensions for Stash embeds
        $this->file_extensions  =   ee()->config->item('stash_file_extensions')     
                                    ? (array) ee()->config->item('stash_file_extensions') 
                                    : array('html', 'md', 'css', 'js', 'rss', 'xml');

        // Support {if var1 IN (var2) }...{/if} style conditionals in Stash templates / tagdata?  
        $this->parse_if_in  = ee()->config->item('stash_parse_if_in') ? ee()->config->item('stash_parse_if_in') : FALSE;

        // include query string when using the @URI context (full page caching)?
        $this->include_query_str = ee()->config->item('stash_query_strings') ? ee()->config->item('stash_query_strings') : FALSE;
        
        // initialise tag parameters
        if (FALSE === $calling_from_hook)
        {
            $this->init();
        }

        // fetch the stash session id
        if ($this->stash_cookie_enabled)
        {
            if ( ! isset(ee()->session->cache['stash']['_session_id']) )
            {   
                // do we have a stash cookie? 
                if ($cookie_data = $this->_get_stash_cookie())
                {
                    // YES - restore session
                    ee()->session->cache['stash']['_session_id'] = $cookie_data['id'];

                    // shall we prune expired variables?
                    if ($this->prune)
                    {   
                        // probability that pruning occurs
                        $prune_chance = 100/$this->prune_probability;

                        // trigger pruning every 1 chance out of $prune_chance
                        if (mt_rand(0, ($prune_chance-1)) === 0) 
                        {   
                            // prune variables with expiry date older than right now 
                            ee()->stash_model->prune_keys();
                        }
                    }
                }
                else
                {
                    if ($this->limit_bots)
                    {
                        // Is the user a human? Legitimate bots don't set cookies so will end up here every page load
                        // Humans who accept cookies only get checked when the cookie is first set
                        self::$_is_human = ($this->_is_bot() ? FALSE : TRUE);
                    }
                    
                    // NO - let's generate a unique id
                    $unique_id = ee()->functions->random();
                    
                    // add to stash array
                    ee()->session->cache['stash']['_session_id'] = $unique_id;
                    
                    // create a cookie; store the creation date in the cookie itself
                    $this->_set_stash_cookie($unique_id);
                }
            }
        
            // create a reference to the session id
            $this->_session_id =& ee()->session->cache['stash']['_session_id'];  
        }
        else
        {
            $this->_session_id = '_global';
        }    
    }
    
    // ---------------------------------------------------------
    
    /**
     * Initialise tag parameters
     *
     * @access public
     * @param  bool      $calling_from_hook Is method being called by an extension hook?
     * @return void 
     */
    public function init($calling_from_hook = FALSE)
    {   
        // make sure we have a Template object to work with, in case Stash is being invoked outside of a template
        if ( ! class_exists('EE_Template'))
        {
            $this->_load_EE_TMPL();
        }
        
        // initialise internal flags
        $this->parse_complete = FALSE;
        $this->_update = FALSE;
        $this->_append = TRUE;
        $this->_embed_nested = FALSE;
        $this->process = 'inline';
        
        // postpone the parsing of the called stash tag?
        if (FALSE === $calling_from_hook)
        {   
            /* process stage:
                start = called prior to template parsing in the current template
                inline = process as a normal tag within the natural parse order of the template
                end = called after all tag parsing has completed
            */
            $this->process  = ee()->TMPL->fetch_param('process', 'inline'); // start | inline | end
            $this->priority = ee()->TMPL->fetch_param('priority', '1'); // ensure a priority is set
        }
        
        // legacy: make 'final' the same as 'end'
        if ($this->process == "final") 
        {
            $this->process = "end";
        }
        
        // tags can't be processed on start, only stash embeds
        if ($this->process == "start") 
        {
            $this->process = "inline";
        }
        
        // allow the site_id to be overridden, for e.g. shared variables across mutliple sites
        $this->site_id = (integer) ee()->TMPL->fetch_param('site_id', $this->site_id);

        // selected bundle
        $bundle = ee()->TMPL->fetch_param('bundle', 'default');

        // lookup the id of an existing bundle, or map to one of the preset bundles
        if ( ! $this->bundle_id = ee()->stash_model->get_bundle_by_name($bundle))
        {
            // not found, fallback to the default
            $this->bundle_id = 1;
        }
        
        // xss scripting protection
        $this->xss_clean = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('xss_clean'));
        
        // if the variable is already set, do we want to replace it's value? Default = yes
        $this->replace = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('replace', 'yes'));

        // parse="yes"?
        $this->set_parse_params();
        
        // do we want to parse any tags and variables inside tagdata? Default = no  
        $this->parse_tags = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('parse_tags'));
        $this->parse_vars = ee()->TMPL->fetch_param('parse_vars', NULL);
        
        // legacy behaviour: if parse_vars is null but parse tags is true, we should make sure vars are parsed too
        if ($this->parse_tags && $this->parse_vars == NULL)
        {
            $this->parse_vars = TRUE;
        }
        else
        {
            $this->parse_vars = (bool) preg_match('/1|on|yes|y/i', $this->parse_vars);
        }
        
        // parsing: how many passes of the template should we make? (more passes = more overhead). Default = 1
        $this->parse_depth = preg_replace('/[^0-9]/', '', ee()->TMPL->fetch_param('parse_depth', 1));
        
        // parsing: parse advanced conditionals. Default = no
        $this->parse_conditionals = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('parse_conditionals'));
        
        // stash type, default to 'variable'
        $this->type = strtolower( ee()->TMPL->fetch_param('type', 'variable') );
        
        // create a stash array in the session if we don't have one
        if ( ! array_key_exists('stash', ee()->session->cache) )
        {
            ee()->session->cache['stash'] = array();
        }   
        
        // determine the memory storage location
        if ($this->type === 'variable')
        {
            // we're setting/getting a 'native' stash variable
            $this->_stash =& ee()->session->cache['stash'];
        }
        elseif ($this->type === 'snippet' || $this->type === 'global')
        {
            // we're setting/getting a global variable {snippet}
            $this->_stash =& ee()->config->_global_vars;
        }
        else
        {
            ee()->output->show_user_error('general', ee()->lang->line('unknown_stash_type') . $this->type);
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Load the EE Template class and register the Stash module
     * Used when Stash is instantiated outside of an EE template
     *
     * @access private
     * @return void 
     */
    private function _load_EE_TMPL()
    {
        // -------------------------------------
        // 'stash_load_template_class' hook
        // -------------------------------------
        if (ee()->extensions->active_hook('stash_load_template_class') === TRUE)
        {
            ee()->remove('TMPL');
            ee()->set('TMPL', ee()->extensions->call('stash_load_template_class'));
        } 
        else 
        {
            require_once APPPATH.'libraries/Template.php';
            ee()->remove('TMPL');
            ee()->set('TMPL', new EE_Template());
            ee()->TMPL->modules = array('stash');
        }
    }
    
    /*
    ================================================================
    Template tags
    ================================================================
    */
    
    /**
     * Shortcut to stash:get or stash:set
     * 
     * @param string     $name The method name being called or context if third tagpart
     * @param array      $arguments The method call arguments
     * 
     * @return void
     */
    public function __call($name, $arguments)
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:foo}
        
        is equivalent to:
        
        {exp:stash:get name="foo"}
        ---------------------------------------------------------
        {exp:stash:foo}
        CONTENT
        {/exp:stash:foo}
        
        is equivalent to:
        
        {exp:stash:set name="foo"}
        CONTENT
        {/exp:stash:set}
        ---------------------------------------------------------
        {exp:stash:bar:foo}
        
        is equivalent to:
    
        {exp:stash:get name="bar:foo"}
        and
        {exp:stash:get context="bar" name="foo"}
        ---------------------------------------------------------
        {exp:stash:bar:foo}
        CONTENT
        {/exp:stash:bar:foo}
        
        is equivalent to:
        {exp:stash:set context="bar" name="foo"}
        CONTENT
        {/exp:stash:set}
        and
        {exp:stash:set name="bar:foo"}
        CONTENT
        {/exp:stash:set}
        --------------------------------------------------------- */
        
        switch($name)
        {
            case 'unset' :
                // make 'unset' - a reserved word - an alias of destroy()
                return call_user_func_array(array($this, 'destroy'), $arguments);
            break;

            case 'static' :
                // make 'static' - a reserved word - an alias of static_cache()
                return call_user_func_array(array($this, 'static_cache'), $arguments);
            break;

            default :
            
            // if there is an extra tagpart, then we have a context and a name
            if (isset(ee()->TMPL->tagparts[2]))
            {   
                ee()->TMPL->tagparams['context'] = $name;
                ee()->TMPL->tagparams['name'] = ee()->TMPL->tagparts[2];  
            }
            else
            {
                ee()->TMPL->tagparams['name'] = $name;
            }
            return ee()->TMPL->tagdata ? $this->set() : $this->get();
        }
    }

    
    /**
     * Set content in the current session, optionally save to the database
     *
     * @access public
     * @param  mixed     $params The name of the variable to retrieve, or an array of key => value pairs
     * @param  string    $value The value of the variable
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return void 
     */
    public function set($params=array(), $value='', $type='variable', $scope='user')
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set name="title" type="snippet"}A title{/exp:stash:set}
        
        OR static call within PHP enabled templates or other add-on: 
        <?php stash::set('title', 'My title') ?>
        --------------------------------------------------------- */
        
        // is this method being called directly?
        if ( func_num_args() > 0)
        {   
            if ( !(isset($this) && get_class($this) == __CLASS__))
            {
                return self::_api_static_call(__FUNCTION__, $params, $type, $scope, $value);
            }
            else
            {
                return $this->_api_call(__FUNCTION__, $params, $type, $scope, $value);
            }
        }
        
        // do we want to set the variable?
        $set = TRUE;
        
        // var name
        $name = ee()->TMPL->fetch_param('name', FALSE);        
        
        // context handling
        $context = ee()->TMPL->fetch_param('context', NULL);
        
        if ( !! $name)
        {
            if ($context !== NULL && count( explode(':', $name)) == 1 )
            {
                $name = $context . ':' . $name;
                ee()->TMPL->tagparams['context'] = NULL;
            }
        }
        
        // replace '@' placeholders with the current context
        $stash_key = $this->_parse_context($name);
        
        // scope
        $scope  = strtolower(ee()->TMPL->fetch_param('scope', $this->default_scope)); // local|user|site
        
        // do we want this tag to return it's tagdata? (default: no)
        $output = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('output'));

        // do we want to parse early global variables in variables retrieved from the cache
        
        // append or prepend passed as parameters?
        if (preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('prepend')))
        {
            $this->_update = TRUE;
            $this->_append = FALSE;
        }
        elseif (preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('append')))
        {
            $this->_update = TRUE;
            $this->_append = TRUE;
        }
        
        // do we want to save this variable in a bundle?
        $bundle = ee()->TMPL->fetch_param('bundle', NULL); // save in a bundle?
        
        // do we want to replace an existing variable?
        if ( !! $name && ! $this->replace && ! $this->_update)
        {
            // try to get existing value
            $existing_value = FALSE;
            
            if ( array_key_exists($stash_key, $this->_stash))
            {
                $existing_value = $this->_stash[$name];
            }
            elseif ($scope !== 'local')
            {
                // narrow the scope to user?
                $session_id = $scope === 'user' ? $this->_session_id : '_global';
                
                $existing_value = ee()->stash_model->get_key(
                    $stash_key, 
                    $this->bundle_id,
                    $session_id, 
                    $this->site_id
                );
            }

            if ( $existing_value !== FALSE)
            {
                // yes, it's already been stashed
                ee()->TMPL->tagdata = $this->_stash[$name] = $existing_value;

                // don't overwrite existing value
                $set = FALSE;
            }
            unset($existing_value);
        }
        
        // do we want to ignore empty tagdata values?
        if ( $not_empty = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('not_empty')) )
        {
            if ( ! $this->not_empty())
            {
                $set = FALSE;
            }
        }
        
        if ($set)
        {
            // support for deprecated no_results_prefix parameter
            $no_results_prefix = ee()->TMPL->fetch_param('no_results_prefix');

            // check for an unprefix parameter to avoid variable name conflicts in nested tags
            if($unprefix = ee()->TMPL->fetch_param('unprefix', $no_results_prefix))
            {
                ee()->TMPL->tagdata = $this->_un_prefix($unprefix, ee()->TMPL->tagdata);
            }
            
            if ( ($this->parse_tags || $this->parse_vars || $this->parse_conditionals) && ! $this->parse_complete)
            {   
                $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
                $this->parse_complete = TRUE; // don't run again
            }
            
            // apply any string manipulations
            ee()->TMPL->tagdata = $this->_clean_string(ee()->TMPL->tagdata);

            // static caching?
            if (ee()->TMPL->fetch_param('bundle') === 'static')
            {
                // we need to parse remaining globals since unlike db cached pages, static pages won't pass through PHP/EE again
                ee()->TMPL->tagdata = ee()->TMPL->parse_globals(ee()->TMPL->tagdata); 

                // parse ACTion id placeholders
                ee()->TMPL->tagdata = ee()->functions->insert_action_ids(ee()->TMPL->tagdata);
            }

            if ( !! $name )
            {                   
                // get params
                $label           = ee()->TMPL->fetch_param('label', $name);
                $save            = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('save'));                        
                $match           = ee()->TMPL->fetch_param('match', NULL); // regular expression to test value against
                $against         = ee()->TMPL->fetch_param('against', ee()->TMPL->tagdata); // text to apply test against
                $filter          = ee()->TMPL->fetch_param('filter', NULL); // regex pattern to search for
                $default         = ee()->TMPL->fetch_param('default', NULL); // default value
                $delimiter       = ee()->TMPL->fetch_param('delimiter', '|'); // implode arrays using this delimiter

                // cache refresh time
                $refresh         = (int) ee()->TMPL->fetch_param('refresh', $this->default_refresh);
                
                // do we want to set a placeholder somewhere in this template ?
                $set_placeholder = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('set_placeholder'));
                
                // make sure we have a value to fallback to for output in current template
                if ($set_placeholder && is_null($default))
                {
                    $default = '';
                }

                // set refresh
                if ($refresh > 0)
                {
                    $refresh = ee()->localize->now + ($refresh * 60);
                }
                
                // regex match
                if ( $match !== NULL && preg_match('/^#(.*)#$/', $match))
                {   
                    $is_match = $this->_matches($match, $against);
                    
                    // did it fail to match the filter?
                    if ( ! $is_match )
                    {
                        // if a default has been specified fallback to it
                        if (! is_null($default))
                        {
                            ee()->TMPL->tagdata = $default;
                        }
                        else
                        {
                            return;
                        }
                    } 
                }               

                // regex filter
                if ( $filter !== NULL && ! is_array(ee()->TMPL->tagdata))
                {
                    preg_match($filter, ee()->TMPL->tagdata, $found);
                    if (isset($found[1]))
                    {
                        ee()->TMPL->tagdata = $found[1];
                    }   
                }
                
                // make sure we're working with a string
                // if we're setting a variable from a global ($_POST, $_GET etc), it could be an array
                if ( is_array(ee()->TMPL->tagdata))
                {   
                    ee()->TMPL->tagdata = array_filter(ee()->TMPL->tagdata, 'strlen');
                    ee()->TMPL->tagdata = implode($delimiter, ee()->TMPL->tagdata);
                }
            
                if ( $this->_update )
                {
                    // We're updating a variable, so lets see if it's in the session or db
                    if ( ! array_key_exists($name, $this->_stash))
                    {
                        $this->_stash[$name] = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
                    }
            
                    // Append or prepend?
                    if ( $this->_append )
                    {
                        $this->_stash[$name] .= ee()->TMPL->tagdata;
                    }
                    else
                    {
                        $this->_stash[$name] = ee()->TMPL->tagdata.$this->_stash[$name];
                    }
                } 
                else
                {
                    $this->_stash[$name] = ee()->TMPL->tagdata;
                }
                
                // replace value into a {placeholder} anywhere in the current template?
                if ($set_placeholder)
                {   
                    ee()->TMPL->template = ee()->functions->var_swap(
                        ee()->TMPL->template, 
                        array($name => $this->_stash[$name])
                    );
                }
                
                // allow user- and site- scoped variables to be saved to the db
                // stop bots saving data to reduce unnecessary load on the server
                if ($save && $scope !== 'local' && self::$_is_human)
                {   
                    // optionally clean data before inserting
                    $parameters = $this->_stash[$name];
                
                    if ($this->xss_clean)
                    {   
                        ee()->security->xss_clean($parameters);
                    }

                    // what's the intended variable scope? 
                    if ($scope === 'site')
                    {
                        $session_filter = '_global';
                    }
                    else
                    {
                        $session_filter =& $this->_session_id;
                    }
                    
                    // let's check if there is an existing record
                    $result = ee()->stash_model->get_key($stash_key, $this->bundle_id, $session_filter, $this->site_id);

                    if ( $result !== FALSE)
                    {
                        // yes record exists, but do we want to update it?
                        $update_key = FALSE;

                        // is the new variable value identical to the value in the cache?
                        // allow append/prepend if the stash key has been created *in this page load*
                        $cache_key = $stash_key. '_'. $this->bundle_id .'_' .$this->site_id . '_' . $session_filter;
                        
                        if ( $result !== $parameters && ($this->replace || ($this->_update && ee()->stash_model->is_inserted_key($cache_key)) ))
                        {   
                            $update_key = TRUE;    
                        }

                        if ($update_key)
                        {
                            // update
                            ee()->stash_model->update_key(
                                $stash_key,
                                $this->bundle_id,
                                $session_filter,
                                $this->site_id,
                                $refresh,
                                $parameters
                            );
                        }
                    }
                    else
                    {   
                        // no record - insert one
                        
                        // Don't save if this template has a 404 header set from a redirect
                        if ( ee()->output->out_type !== "404")
                        {
                            ee()->stash_model->insert_key(
                                $stash_key,
                                $this->bundle_id,
                                $session_filter,
                                $this->site_id,
                                $refresh,
                                $parameters,
                                $label
                            );
                        }
                    }
                }
            }
            else
            {
                // no name supplied, so let's assume we want to set sections of content within tag pairs
                // {stash:my_variable}...{/stash:my_variable}
                $vars = array();
                $tagdata = ee()->TMPL->tagdata;
            
                // context handling
                if ( $context !== NULL ) 
                {
                    $prefix = $context . ':';
                    ee()->TMPL->tagparams['context'] = NULL;
                }
                else
                {
                    $prefix = '';
                }
                
                // if the tagdata has been parsed, we need to generate a new array of tag pairs
                // this permits dynamic tag pairs, e.g. {stash:{key}}{/stash:{key}} 
                if ($this->parse_complete)
                {
                    if (version_compare(APP_VER, '4.0', '>=')) 
                    { 
                        $tag_vars = ee('Variables/Parser')->extractVariables(ee()->TMPL->tagdata); 
                    }
                    else
                    {
                        $tag_vars = ee()->functions->assign_variables(ee()->TMPL->tagdata);
                    }
                    
                    $tag_pairs = $tag_vars['var_pair'];
                }
                else
                {
                    $tag_pairs =& ee()->TMPL->var_pair;
                }
            
                foreach($tag_pairs as $key => $val)
                {
                    if (strncmp($key, 'stash:', 6) ==  0)
                    {
                        $pattern = '/'.LD.$key.RD.'(.*)'.LD.'\/'.$key.RD.'/Usi';
                        preg_match($pattern, $tagdata, $matches);
                        if (!empty($matches))
                        {       
                            // set the variable, but cleanup first in case there are any nested tags
                            ee()->TMPL->tagparams['name'] = $prefix . str_replace('stash:', '', $key);
                            ee()->TMPL->tagdata = preg_replace('/'.LD.'stash:[a-zA-Z0-9\-_]+'.RD.'(.*)'.LD.'\/stash:[a-zA-Z0-9\-_]+'.RD.'/Usi', '', $matches[1]);
                            $this->parse_complete = TRUE; // don't allow tagdata to be parsed
                            $this->set();
                        }   
                    }
                }
            
                // reset tagdata to original value
                ee()->TMPL->tagdata = $tagdata;
                unset($tagdata);
            }
        }
        
        if ( !! $name)
        {
            if ( $bundle !== NULL)
            {
                if ( ! isset(self::$bundles[$bundle]))
                {
                    self::$bundles[$bundle] = array();
                }
                self::$bundles[$bundle][$name] = $this->_stash[$name];
            }

            ee()->TMPL->log_item('Stash: SET '. $name . ' to value: ' . '<textarea rows="6" cols="60" style="width:100%;">' . htmlentities($this->_stash[$name]) . '</textarea>');  
        }
        
        if ($output)
        {
            return ee()->TMPL->tagdata;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Get content from session, database, $_POST/$_GET superglobals or file
     *
     * @access public
     * @param  mixed     $params The name of the variable to retrieve, or an array of key => value pairs
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return string 
     */
    public function get($params='', $type='variable', $scope='user')
    {       
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:get name="title"}
        
        OR static call within PHP enabled templates or other add-on: 
        <?php echo stash::get('title') ?>
        --------------------------------------------------------- */

        // is this method being called directly?
        if ( func_num_args() > 0)
        {   
            if ( !(isset($this) && get_class($this) == __CLASS__))
            {
                return self::_api_static_call(__FUNCTION__, $params, $type, $scope);
            }
            else
            {
                return $this->_api_call(__FUNCTION__, $params, $type, $scope);
            }
        }
        
        if ( $this->process !== 'inline') 
        {
            if ($out = $this->_post_parse(__FUNCTION__)) return $out;
        }

        $name           = ee()->TMPL->fetch_param('name');
        $default        = ee()->TMPL->fetch_param('default', NULL); // default value
        $dynamic        = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('dynamic'));
        $save           = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('save'));     
        $scope          = strtolower(ee()->TMPL->fetch_param('scope', $this->default_scope)); // local|user|site
        $bundle         = ee()->TMPL->fetch_param('bundle', NULL); // save in a bundle?
        $match          = ee()->TMPL->fetch_param('match', NULL); // regular expression to test value against
        $filter         = ee()->TMPL->fetch_param('filter', NULL); // regex pattern to search for

        // do we want this tag to return the value, or just set the variable quietly in the background?
        $output = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('output', 'yes'));
        
        // parse any vars in the $name parameter?
        if ($this->parse_vars)
        {
            $name = $this->_parse_template_vars($name);
        }
        
        // low search support - do we have a query string?
        $low_query = ee()->TMPL->fetch_param('low_query', NULL);

        // context handling
        $context = ee()->TMPL->fetch_param('context', NULL);
        $global_name = $name;
        
        if ($context !== NULL && count( explode(':', $name)) == 1 )
        {
            $name = $context . ':' . $name;
            ee()->TMPL->tagparams['context'] = NULL;
        }
        
        // parse '@' context pointers
        $name_in_context = $this->_parse_context($name);
        
        // read from file?
        $file = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('file'));
        $file_name = ee()->TMPL->fetch_param('file_name', FALSE); // default value
        
        // when to parse the variable if reading from a file and saving: 
        // before we save it to database (set) or when we retrieve it (get), or on set and get (both)
        $parse_stage = strtolower(ee()->TMPL->fetch_param('parse_stage', 'set')); // set|get|both
        
        if ( !! $file_name)
        {
            $file = TRUE;
        }
        else
        {
            $file_name = $name;
        }
        
        // the variable value
        $value = NULL;
        
        // do we want to set the variable?
        $set = FALSE;

        // is it a segment? We need to support these in stash template files
        if (strncmp($name, 'segment_', 8) === 0)
        {
            $seg_index = substr($name, 8);
            $value = ee()->uri->segment($seg_index);
        }

        // let's see if it's been stashed before in this page load
        elseif ( is_string($name) && array_key_exists($name, $this->_stash))
        {
            $value = $this->_stash[$name];          
        }
        
        // let's see if it exists in the current context
        elseif ( is_string($name_in_context) && array_key_exists($name_in_context, $this->_stash))
        {
            $value = $this->_stash[$name_in_context];
            $name = $name_in_context;       
        }
        
        // not found in memory
        else
        {
            // has it been bundled?
            if ( ! is_null($bundle) && isset(self::$bundles[$bundle][$name]))
            {
                $value = $this->_stash[$name] = self::$bundles[$bundle][$name];
            }
            elseif ( ! $this->_update && ! ($dynamic && ! $save) && $scope !== 'local')
            {
                // let's look in the database table cache, but only if if we're not
                // appending/prepending or trying to register a global without saving it
                
                // narrow the scope to user?
                $session_id = $scope === 'user' ? $this->_session_id : '_global';
            
                // replace '@' placeholders with the current context
                $stash_key = $this->_parse_context($name);
                    
                // look for our key
                if ( $parameters = ee()->stash_model->get_key(
                    $stash_key, 
                    $this->bundle_id,
                    $session_id, 
                    $this->site_id
                ))
                {   
                    // save to session 
                    $value = $this->_stash[$name] = $parameters;
                }
            }

            // Are we looking for a superglobal or uri segment?
            if ( ($dynamic && $value === NULL) || ($dynamic && $this->replace) )
            {   
                $from_global = FALSE;
                    
                // low search support
                if ($low_query !== NULL)
                {
                    // has the query string been supplied or is it in POST?
                    if (strncmp($low_query, 'stash:', 6) == 0)
                    {
                        $low_query = substr($low_query, 6);
                        $low_query = $this->_stash[$low_query];
                    }

                    $low_query = @unserialize(base64_decode(str_replace('_', '/', $low_query)));

                    if (isset( $low_query[$global_name] ))
                    {
                        $from_global = $low_query[$global_name];
                        unset($low_query);
                    }
                    else
                    {
                        // set to empty value
                        $from_global = '';
                    }
                }
                
                // or is it in the $_POST or $_GET superglobals ( run through xss_clean() )?
                if ($from_global === FALSE)
                {
                    $from_global = ee()->input->get_post($global_name, TRUE);
                }
                
                if ($from_global === FALSE)
                {
                    // no, so let's check the uri segments
                    $segs = ee()->uri->segment_array();

                    foreach ( $segs as $index => $segment )
                    {
                        if ( $segment == $global_name && array_key_exists( ($index+1), $segs) )
                        {
                            $from_global = $segs[($index+1)];
                            break;
                        }
                    }
                }
                
                if ($from_global !== FALSE)
                {
                    // save to stash, and optionally to database, if save="yes"
                    $value = $from_global;
                    $set = TRUE;
                }
            }
            
            // Are we reading a file?
            if ( ($file && $value === NULL) || ($file && $this->replace) || ($file && $this->file_sync) )
            {                   
                // extract and remove the file extension, if provided
                $ext = 'html'; // default extension

                #  PHP 5.3+ only
                # $file_ext = preg_filter('/^.*\./', '', $file_name);   
                $file_ext = NULL;
                if ( preg_match('/^.*\./', $file_name) )
                {
                    $file_ext = preg_replace('/^.*\./', '', $file_name);
                }

                // make sure the extension is allowed
                if ( ! is_null($file_ext))
                {
                    if ( in_array($file_ext, $this->file_extensions))
                    {
                        $ext = $file_ext;
                    }
                }
                
                // strip file ext (if any) and make sure we have a safe url encoded file path
                $file_path = preg_replace('/\.[^.]*$/', '', $file_name);
                #$file_path = explode(':', $file_path);
                $file_path = preg_split("/[:\/]+/", $file_path);

                foreach($file_path as &$part)
                {
                    // make sure it's a valid url title
                    $part = str_replace('.', '', $part);

                    // insist upon alphanumeric characters and - or _
                    $part = trim(preg_replace('/[^a-z0-9\-\_]+/', '-', strtolower($part)), '-');
                }
                unset($part); // remove reference

                // remove any empty url parts
                $file_path = array_filter($file_path);
                
                $file_path = $this->path . implode('/', $file_path) . '.' . $ext;

                if ( file_exists($file_path))
                {   
                    ee()->TMPL->log_item("Stash: reading file " . $file_path);

                    $value = str_replace("\r\n", "\n", file_get_contents($file_path));

                    $set = TRUE;
                    
                    // disable tag parsing on set when parse_stage is 'get'
                    if ($parse_stage == 'get')
                    {
                        $this->parse_complete = TRUE;
                    }
                }
                else
                {
                    ee()->output->show_user_error('general', sprintf(ee()->lang->line('stash_file_not_found'), $file_path));
                    return;
                }
            }
        }
        
        // set to default value if it NULL or empty string (this permits '0' to be a valid value)
        if ( ($value === NULL || $value === '') && ! is_null($default))
        {   
            $value = $default;
            $set = TRUE;    
        }
        
        // create/update value of variable if required
        // note: don't save if we're updating a variable (to avoid recursion)
        if ( $set && ! $this->_update)
        {   
            ee()->TMPL->tagparams['name'] = $name;
            ee()->TMPL->tagparams['output'] = 'yes';
            ee()->TMPL->tagdata = $value;
            $this->replace = TRUE;
            $value = $this->set();  
        }
            
        ee()->TMPL->log_item('Stash: RETRIEVED '. $name . ' with value: <textarea rows="6" cols="60" style="width:100%;">' . htmlentities($value) . '</textarea>');
        
        // save to bundle
        if ($bundle !== NULL)
        {
            if ( ! isset(self::$bundles[$bundle]))
            {
                self::$bundles[$bundle] = array();
            }
            self::$bundles[$bundle][$name] = $value;
        }            
        
        // are we outputting the variable?
        if ($output)
        {
            if ( ! $file )
            {
                $value = $this->_parse_output($value, $match, $filter, $default);
            }
            else
            {
                // If this is a variable loaded originally from a file, parse if the desired parse stage is on retrieval (parse_stage="get|both")
                if ( ($parse_stage == 'get' || $parse_stage == 'both'))
                {
                    $this->parse_complete = FALSE; // enable parsing
                    $this->parse_vars = TRUE; // ensure early global and stash vars are always fully parsed
                    $value = $this->_parse_output($value, $match, $filter, $default);
                }
                else
                {
                    // ensure early global vars are always parsed
                    $value = $this->_parse_template_vars($value);
                }
            }
            return $value;
        }
    }

    // ---------------------------------------------------------
    
    /**
     * Define default/fallback content for a stash variable from enclosed tagdata
     *
     * @access public
     * @return string 
     */
    public function block()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:block name="page_content"}
            default content
        {/exp:stash:block}

        {exp:stash:block:page_content}
            default content
        {/exp:stash:block:page_content}
        --------------------------------------------------------- */

        $tag_parts = ee()->TMPL->tagparts;

        if ( is_array( $tag_parts ) && isset( $tag_parts[2] ) )
        {
            if (isset($tag_parts[3]))
            {
                ee()->TMPL->tagparams['context'] = ee()->TMPL->fetch_param('context', $tag_parts[2]);
                ee()->TMPL->tagparams['name'] = ee()->TMPL->fetch_param('name', $tag_parts[3]);
            }
            else
            {
                // no context or name provided?
                if ( ! isset(ee()->TMPL->tagparams['name']) AND ! isset(ee()->TMPL->tagparams['context']))
                {
                     ee()->TMPL->tagparams['context'] = 'block';
                }
                ee()->TMPL->tagparams['name'] = ee()->TMPL->fetch_param('name', $tag_parts[2]);
            }
        }

        // is this block dependent on one or more other stash variables *being set*?
        if ($requires = ee()->TMPL->fetch_param('requires', FALSE))
        {
            $requires = explode('|', $requires);
            foreach ($requires as $var) 
            {
                if ( ! isset($this->_stash[$var]))
                {
                    return '';
                }
            }
        }

        ee()->TMPL->tagparams['default'] = ee()->TMPL->tagdata;
        ee()->TMPL->tagdata = FALSE;
        return $this->get();
    }

    // ---------------------------------------------------------
    
    /**
     * Inject a stash embed into a variable or block
     *
     * @access public
     * @return string|void
     */
    public function extend()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:extend name="content" with="views:my_embed" stash:my_var="value"}
        
        Or as a tag pair with an arbitrary 4th tagpart:

        {exp:stash:extend:block name="content" with="views:my_embed"}
            {stash:my_var}value{/stash:my_var}
        {/exp:stash:extend:block}
    
        --------------------------------------------------------- */

        if ( FALSE === $with = ee()->TMPL->fetch_param('with', FALSE)) return;
         
        $embed_params = array();
        unset(ee()->TMPL->tagparams['with']);

        // have values other than embed name been passed in the "with" param? These should be passed as parameters to the embed:
        $with = explode(' ', $with, 2);

        // extract embed vars passed as params
        foreach (ee()->TMPL->tagparams as $key => $val)
        {
            if (strncmp($key, 'stash:', 6) == 0)
            {
                $embed_params[] = $key . '="'. $val .'"';
            }
        }

        // if this is a tag pair, construct an embed_extend tag and pass data enclosed by {stash:...} pairs to it
        if (ee()->TMPL->tagdata)
        {
            // construct the embed_extend tag
            ee()->TMPL->tagdata = LD . 'exp:stash:embed_extend name="' . $with[0] . '"' . (isset($with[1]) ? ' ' . $with[1] : '') . ' ' . implode(" ", $embed_params) . RD . ee()->TMPL->tagdata . LD . '/exp:stash:embed_extend' . RD;
        }
        else
        {
            // construct the embed tag
            ee()->TMPL->tagdata = LD . 'exp:stash:embed name="' . $with[0] . '"' . (isset($with[1]) ? ' ' . $with[1] : '') . ' ' . implode(" ", $embed_params) . RD;
        }
    
        // escape it?
        if ( (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('escape')) )
        {
            ee()->TMPL->tagdata = LD .'stash:nocache'. RD . ee()->TMPL->tagdata . LD .'/stash:nocache'. RD;
        }
        
        // inject the embed into a variable / block
        return $this->set();
     
    }

    // ---------------------------------------------------------
    
    /**
     * Pass variables to an embed via variable pairs in tagdata
     *
     * @access public
     * @return string
     */
    public function embed_extend()
    {
        if (ee()->TMPL->tagdata)
        {
            $embed_vars = array();

            foreach(ee()->TMPL->var_pair as $key => $val)
            {
                if (strncmp($key, 'stash:', 6) ==  0)
                {
                    $pattern = '/'.LD.$key.RD.'(.*)'.LD.'\/'.$key.RD.'/Usi';
                    preg_match($pattern, ee()->TMPL->tagdata, $matches);

                    if ( ! empty($matches))
                    {
                        $embed_vars[$key] = $matches[1];
                    }   
                }
            }

            if (is_array(ee()->TMPL->tagparams))
            {
                ee()->TMPL->tagparams = array_merge($embed_vars, ee()->TMPL->tagparams);
            }
            else
            {
                 ee()->TMPL->tagparams = $embed_vars;
            }

            ee()->TMPL->tagdata = '';
        }

        return $this->embed();
    }

    // ---------------------------------------------------------
    
    /**
     * Clone a variable / list
     *
     * @access public
     * @return string 
     */
    public function copy()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:copy 
            name="original_name" 
            context="original_context" 
            scope="original_scope"
            type="original_type"
            copy_name="copy_name" 
            copy_context="copy_context"
            copy_scope="copy_scope"
            copy_type="copy_type"
        }
        --------------------------------------------------------- */

        // get the original variable value, restricting which params are passed to a minimum
        ee()->TMPL->tagdata = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));

        // prep the tagparams with the values for the clone
        ee()->TMPL->tagparams['name']      = ee()->TMPL->fetch_param('copy_name', FALSE);
        ee()->TMPL->tagparams['context']   = ee()->TMPL->fetch_param('copy_context', NULL);
        ee()->TMPL->tagparams['scope']     = strtolower(ee()->TMPL->fetch_param('copy_scope', $this->default_scope));
        ee()->TMPL->tagparams['type']      = ee()->TMPL->fetch_param('copy_type', 'variable');

        // re-initialise Stash with the new params
        $this->init();
        
        // clone the bugger
        return $this->set();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Append the specified value to an already existing variable.
     *
     * @access public
     * @return void 
     */
    public function append()
    {
        $this->_update = TRUE;
        $this->_append = TRUE;
        return $this->set();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Prepend the specified value to an already existing variable.
     *
     * @access public
     * @return void 
     */
    public function prepend()
    {
        $this->_update = TRUE;
        $this->_append = FALSE;
        return $this->set();
    }
        
    // ---------------------------------------------------------
    
    /**
     * Single tag version of set(), for when you need to use a 
     * plugin as a tag parameter (always use with parse="inward")
     * 
     *
     * @access public
     * @param bool   $update Update an existing stashed variable
     * @return void 
     */
    public function set_value()
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}
        --------------------------------------------------------- */
        
        ee()->TMPL->tagdata = ee()->TMPL->fetch_param('value', FALSE);
        
        if ( ee()->TMPL->tagdata !== FALSE )
        {
            return $this->set();
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Single tag version of append()
     *
     * @access public
     * @return void 
     */
    public function append_value()
    {
        $this->_update = TRUE;
        $this->_append = TRUE;
        return $this->set_value();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Single tag version of prepend()
     *
     * @access public
     * @return void 
     */
    public function prepend_value()
    {
        $this->_update = TRUE;
        $this->_append = FALSE;
        return $this->set_value();
    }
    
    // ---------------------------------------------------------
    
    /**
     * Set the current context
     *
     * @access public
     * @return void
     */
    public function context()
    {
        if ( !! $name = ee()->TMPL->fetch_param('name', FALSE) )
        {
            self::$context = $name;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Checks if a variable or string is empty or non-existent, handy for conditionals
     *
     * @access public
     * @param $string a string to test
     * @return integer
     */
    public function not_empty($string = NULL)
    {
        /* Sample use
        ---------------------------------------------------------
        Check a native stash variable, global variable or snippet is not empty:
        {if {exp:stash:not_empty type="snippet" name="title"} }
            Yes! {title} is not empty
        {/if}
        
        Check any string or variable is not empty even if it's not been Stashed:
        {if {exp:stash:not_empty:string}{my_string}{/exp:stash:not_empty:string} }
            Yes! {my_string} is not empty
        {/if}
        --------------------------------------------------------- */
        if ( ! is_null($string))
        {
            $test = $string;
        }
        elseif ( ee()->TMPL->tagdata )
        {
            // parse any vars in the string we're testing
            $this->_parse_sub_template(FALSE, TRUE);
            $test = ee()->TMPL->tagdata;
        }
        else
        {
            $test = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
        }
        
        $value  = str_replace( array("\t", "\n", "\r", "\0", "\x0B"), '', trim($test));
        return empty( $value ) ? 0 : 1;
    }   

    // ---------------------------------------------------------

    /**
     * Checks if a variable or string has any content, handy for conditionals
     *
     * @access public
     * @param $string a string to test
     * @return integer
     */
    public function is_empty($string = NULL)
    {
        /* Sample use
        ---------------------------------------------------------
        Check a native stash variable, global variable or snippet is empty:
        {if {exp:stash:is_empty type="snippet" name="title"} }
            Yes! {title} is empty
        {/if}

        Check any string or variable is not empty even if it's not been Stashed:
        {if {exp:stash:is_empty:string}{my_string}{/exp:stash:is_empty:string} }
            Yes! {my_string} is empty
        {/if}
        --------------------------------------------------------- */
        return $this->not_empty($string) == 0 ? 1 : 0;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Serialize a multidimenisional array and save as a variable
     *
     * @access public
     * @return string 
     */
    public function set_list()
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set_list name="blog_entries"}
            {stash:item_title}{title}{/stash:item_title}
            {stash:item_img_url}{img_url}{/stash:item_img_url}
            {stash:item_copy}{copy}{/stash:item_copy}
        {/exp:stash:set_list}
        --------------------------------------------------------- */
        
        // name and context
        $name = ee()->TMPL->fetch_param('name', FALSE);        
        $context = ee()->TMPL->fetch_param('context', NULL);
        $scope  = strtolower(ee()->TMPL->fetch_param('scope', $this->default_scope)); // local|user|site

        // are we trying to *overwrite* an existing list (replace it but not change the existing list if the new list is empty)?
        $overwrite = ee()->TMPL->fetch_param('overwrite', FALSE); 
        if ($overwrite)
        {
            $this->replace = TRUE;
        }
        
        if ( !! $name)
        {
            if ($context !== NULL && count( explode(':', $name)) == 1 )
            {
                $name = $context . ':' . $name;
            }
        }
        
        // replace '@' placeholders with the current context
        $stash_key = $this->_parse_context($name);
        
        // no results prefix
        $prefix = ee()->TMPL->fetch_param('prefix', NULL);
        
        // check for prefixed no_results block
        if ( ! is_null($prefix))
        {
            $this->_prep_no_results($prefix);
        }

        // Unprefix common variables in wrapped tags
        if($unprefix = ee()->TMPL->fetch_param('unprefix'))
        {
            ee()->TMPL->tagdata = $this->_un_prefix($unprefix, ee()->TMPL->tagdata);
        }
        
        // do we want to replace an existing list variable?
        $set = TRUE;

        if ( ! $this->replace && ! $this->_update)
        {   
            // try to get existing value
            $existing_value = FALSE;
            
            if ( array_key_exists($name, $this->_stash))
            {
                $existing_value = $this->_stash[$name];
            }
            elseif ($scope !== 'local')
            {
                // narrow the scope to user?
                $session_id = $scope === 'user' ? $this->_session_id : '_global';
                
                $existing_value = ee()->stash_model->get_key(
                    $stash_key, 
                    $this->bundle_id,
                    $session_id, 
                    $this->site_id
                );
            }

            if ( $existing_value !== FALSE)
            {   
                // yes, it's already been stashed, make sure it's in the stash memory cache
                ee()->TMPL->tagdata = $this->_stash[$name] = $existing_value;
                
                // don't overwrite existing value
                $set = FALSE;
            }
            unset($existing_value);
        }
        
        if ($set)
        {  
            // do any parsing and string transforms before making the list
            ee()->TMPL->tagdata = $this->_parse_output(ee()->TMPL->tagdata);
            $this->parse_complete = TRUE; // make sure we don't run parsing again, if we're saving the list

            // get stash variable pairs (note: picks up outer pairs, nested pairs and singles are ignored)
            preg_match_all('#'.LD.'(stash:[a-z0-9\-_]+)'.RD.'.*?'.LD.'/\g{1}'.RD.'#ims', ee()->TMPL->tagdata, $matches);

            if (isset($matches[1]))
            {
                ee()->TMPL->var_pair = array_flip(array_unique($matches[1]));
            }

            // get the first key and see if it repeats
            $keys = array_keys(ee()->TMPL->var_pair);
        
            if ( ! empty($keys))
            {
                $first_key = $keys[0];

                preg_match_all('/'. LD . $first_key . RD . '/', ee()->TMPL->tagdata, $matches);
        
                if (count($matches[0]) > 1)
                {
                    // yes we have repeating keys, so let's split the tagdata up into rows
                    ee()->TMPL->tagdata = str_replace(
                            LD . $first_key . RD, 
                            $this->_list_delimiter . LD . $first_key . RD,
                            ee()->TMPL->tagdata
                    );
            
                    // get an array of rows, remove first element which will be empty
                    $rows = explode($this->_list_delimiter, ee()->TMPL->tagdata);
                    array_shift($rows);
            
                    // serialize each row and append
                    // bracket the serilaized string with delimiters
                    $tagdata = '';
                    foreach($rows as $row)
                    {
                        ee()->TMPL->tagdata = $row;
                        $this->_serialize_stash_tag_pairs();
                        if ( ! empty(ee()->TMPL->tagdata))
                        {
                            $tagdata .= $this->_list_delimiter . ee()->TMPL->tagdata;
                        }
                    }
                    ee()->TMPL->tagdata = trim($tagdata, $this->_list_delimiter);
                }
                else
                {
                    //  get the stash var pairs values
                    $this->_serialize_stash_tag_pairs();
                }
        
                if ( $this->not_empty(ee()->TMPL->tagdata))
                {
                    // set the list, but do we need to disable match/against?
                    if  ( FALSE !== ee()->TMPL->fetch_param('against', FALSE))
                    {
                        // already matched/against a specified column in the list, so disable match/against
                        unset(ee()->TMPL->tagparams['match']);
                        unset(ee()->TMPL->tagparams['against']);
                    }

                    return $this->set();    
                }
            }
            else
            {
                // make sure this variable is marked as empty, so subsquent get_list() calls return no_results
                if (FALSE === $overwrite)
                {
                    $this->_stash[$name] = '';
                }

                if ((bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('output'))) // default="no"
                { 
                    // optionally parse and return no_results tagdata
                    // note: output="yes" with set_list should only be used for debugging
                    return $this->_no_results(); 
                }
                else
                {   
                    // parse no_results tagdata, but don't output
                    // note: unless parse_tags="yes", no parsing would occur
                    $this->_no_results();
                }
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Append to array
     *
     * @access public
     * @return string 
     */
    public function append_list()
    {   
        return $this->_update_list(TRUE); 
    }
    
    // ---------------------------------------------------------
    
    /**
     * Prepend to array
     *
     * @access public
     * @return string 
     */
    public function prepend_list()
    {   
        return $this->_update_list(FALSE); 
    }


    // ---------------------------------------------------------
    
    /**
     * Append / prepend to array
     *
     * @access private
     * @param  bool  $append Append or prepend to an existing list?
     * @return string 
     */
    private function _update_list($append=TRUE)
    {
        $name = ee()->TMPL->fetch_param('name');
        $context = ee()->TMPL->fetch_param('context', NULL);
        ee()->TMPL->tagdata = $this->_parse_output(ee()->TMPL->tagdata);
        $this->parse_complete = TRUE; // make sure we don't run parsing again

        // get stash variable pairs (note: picks up outer pairs, nested pairs and singles are ignored)
        preg_match_all('#'.LD.'(stash:[a-z0-9\-_]+)'.RD.'.*?'.LD.'/\g{1}'.RD.'#ims', ee()->TMPL->tagdata, $matches);

        if (isset($matches[1]))
        {
            ee()->TMPL->var_pair = array_flip(array_unique($matches[1]));
        }

        // format our list
        $this->_serialize_stash_tag_pairs();
        
        if ( $this->not_empty(ee()->TMPL->tagdata))
        {
            // does the list really exist?
            if ($context !== NULL && count( explode(':', $name)) == 1 )
            {
                $name = $context . ':' . $name;
            }
            $name_in_context = $this->_parse_context($name);

            // get the current value of the list
            $current_value = '';

            if (array_key_exists($name, $this->_stash))
            {
                $current_value = $this->_stash[$name];
            }
            elseif(array_key_exists($name_in_context, $this->_stash))
            {
                $current_value = $this->_stash[$name_in_context];
            }

            // check that the list has a value before appending/prepending
            if ( $this->not_empty($current_value))
            {
                if ($append)
                {
                    ee()->TMPL->tagdata = $this->_list_delimiter . ee()->TMPL->tagdata;
                }
                else
                {
                    ee()->TMPL->tagdata =  ee()->TMPL->tagdata . $this->_list_delimiter;
                }
            }

            // update the list, but do we need to disable match/against?
            if  ( FALSE !== ee()->TMPL->fetch_param('against', FALSE))
            {
                // already matched/against a specified column in the list, so disable match/against
                unset(ee()->TMPL->tagparams['match']);
                unset(ee()->TMPL->tagparams['against']);
            }

            return $append ? $this->append() : $this->prepend();
        }
    }

    // ---------------------------------------------------------
    
    /**
     * Create a union of two or more existing lists 
     * Lists *must* share the same keys and be *already in memory*
     *
     * @access public
     * @return string 
     */
    public function join_lists()
    {   
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:join_lists 
            name="my_combined_list"
            lists="list_1,list_2,list3"
        }
        --------------------------------------------------------- */    

        // list names
        $lists = ee()->TMPL->fetch_param('lists');
        $lists = explode(',', $lists);

        // create an array of values
        $values = array();
        foreach($lists as $name)
        {
            if (array_key_exists($name, $this->_stash))
            {
                // ignore empty lists
                if ( ! empty($this->_stash[$name]))
                {
                    $values[] = $this->_stash[$name];
                }
            }
        }

        // implode values into the format of a delimited list, and set as the tagdata
        ee()->TMPL->tagdata = implode($this->_list_delimiter, $values);

        // set as a new variable
        return $this->set();  
    }

    public function split_list()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:split_list 
            name="my_list_fragment"
            list="list_1"
            match="#^blue$#"
            against="colour"
        }
        --------------------------------------------------------- */  

        // the original list
        $old_list = ee()->TMPL->fetch_param('list', FALSE);

        // the new list
        $new_list = ee()->TMPL->fetch_param('name', FALSE);

        // limit the number of rows to copy?
        $limit = ee()->TMPL->fetch_param('limit',  FALSE);

        if ($old_list && $new_list)
        {
            ee()->TMPL->tagparams['name'] = $old_list;

            // apply filters to the original list and generate an array
            $list = $this->rebuild_list();

            // apply limit
            if ($limit !== FALSE)
            {
                $list = array_slice($list, 0, $limit);
            }

            // flatten the list array into a string, ready for setting as a variable
            ee()->TMPL->tagdata = $this->flatten_list($list);

            // reset the name parameter
            ee()->TMPL->tagparams['name'] = $new_list;

            // unset params used for filtering
            unset(ee()->TMPL->tagparams['match']);
            unset(ee()->TMPL->tagparams['against']);

            // set as a new variable
            return $this->set(); 
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve a serialised array of items, explode and replace into tagdata
     *
     * @access public
     * @return string 
     */
    public function get_list($params=array(), $value='', $type='variable', $scope='user')
    {                           
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:get_list name="page_items" orderby="item_title" sort="asc"}
            <h2>{item_title}</h2>
            <img src="{item_img_url}" />
            {item_copy}
        {/exp:stash:get_list}
        --------------------------------------------------------- */    

        // is this method being called directly?
        if ( func_num_args() > 0)
        {   
            if ( !(isset($this) && get_class($this) == __CLASS__))
            {
                return self::_api_static_call(__FUNCTION__, $params, $type, $scope, $value);
            }
            else
            {
                return $this->_api_call(__FUNCTION__, $params, $type, $scope, $value);
            }
        }

        if ( $this->process !== 'inline') 
        {
            if ($out = $this->_post_parse(__FUNCTION__)) return $out;
        }

        $limit              = ee()->TMPL->fetch_param('limit',  FALSE);
        $offset             = ee()->TMPL->fetch_param('offset', 0);
        $default            = ee()->TMPL->fetch_param('default', '');          // default value
        $filter             = ee()->TMPL->fetch_param('filter', NULL);         // regex pattern to search final output for
        $prefix             = ee()->TMPL->fetch_param('prefix', NULL);         // optional namespace for common vars like {count}
        $require_prefix     = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('require_prefix', 'yes')); // if prefix="" is set, only placeholders using the prefix will be parsed
        $paginate           = ee()->TMPL->fetch_param('paginate', FALSE);
        $paginate_param     = ee()->TMPL->fetch_param('paginate_param', NULL); // if using query string style pagination
        $track              = ee()->TMPL->fetch_param('track',  FALSE);        // one or more column values to track as a static variable, e.g. entry_id|color
        
        $list_html      = '';
        $list_markers   = array();  
        
        // check for prefixed no_results block
        if ( ! is_null($prefix))
        {
            $this->_prep_no_results($prefix);
        }
                    
        // retrieve the list array
        $list = $this->rebuild_list();

        // return no results if this variable has no value
        if ( empty($list))
        {   
            return $this->_no_results();
        }
        
        // apply prefix
        if ( ! is_null($prefix))
        {
            foreach ($list as $index => $array)
            {
                foreach ($array as $k => $v)
                {
                    $list[$index][$prefix.':'.$k] = $v;

                     // do we want to stop un-prefixed variables being parsed?
                    if ($require_prefix)
                    {
                        unset($list[$index][$k]);
                    }
                }
            }
        }

        // absolute results total
        $absolute_results = count($list);

        // does limit contain a fraction?
        if ($limit)
        {   
            $limit = $this->_parse_fraction($limit, $offset, $absolute_results);
        }

        // does offset contain a fraction, e.g. '1/3' ?
        if ($offset)
        {
            $offset = $this->_parse_fraction($offset, 0, $absolute_results);
        }
        
        // pagination
        if ($paginate)
        {   
            // remove prefix if used in the paginate tag pair
            if ( ! is_null($prefix))
            {
                if (preg_match("/(".LD.$prefix.":paginate".RD.".+?".LD.'\/'.$prefix.":paginate".RD.")/s", ee()->TMPL->tagdata, $paginate_match))
                {
                    $paginate_template = str_replace($prefix.':','', $paginate_match[1]);
                    ee()->TMPL->tagdata = str_replace($paginate_match[1], $paginate_template, ee()->TMPL->tagdata);
                }
            }
                    
            // pagination template
            ee()->load->library('pagination');
            
            // are we passing the offset in the query string?
            if ( ! is_null($paginate_param))
            {
                // prep the base pagination object
                ee()->pagination->query_string_segment = $paginate_param;
                ee()->pagination->page_query_string = TRUE;
            }
            
            // create a pagination object instance
            if (version_compare(APP_VER, '2.8', '>=')) 
            { 
                $this->pagination = ee()->pagination->create();
            } 
            else
            {
                $this->pagination = new Pagination_object(__CLASS__);
            }

            // pass the offset to the pagination object
            if ( ! is_null($paginate_param))
            {
                // we only want the offset integer, ignore the 'P' prefix inserted by EE_Pagination
                $this->pagination->offset = filter_var(ee()->input->get($paginate_param, TRUE), FILTER_SANITIZE_NUMBER_INT);
                
                if ( ! is_null(ee()->TMPL->fetch_param('paginate_base', NULL)))
                {
                    // make sure paginate_base ends with a '?', if specified
                    $base=ee()->TMPL->tagparams['paginate_base'];
                    ee()->TMPL->tagparams['paginate_base'] = $base.((!strpos($base, '?'))? '?': '');
                }
            }
            else
            {
                $this->pagination->offset = 0;
            }

            // determine pagination limit & total rows
            $page_limit = $limit ? $limit : 100; // same default limit as channel entries module
            $page_total_rows = $absolute_results - $offset;
            
            if (version_compare(APP_VER, '2.8', '>=')) 
            { 
                 // find and remove the pagination template from tagdata wrapped by get_list
                ee()->TMPL->tagdata = $this->pagination->prepare(ee()->TMPL->tagdata);

                // build
                $this->pagination->build($page_total_rows, $page_limit);
            }
            else
            {
                $this->pagination->per_page = $page_limit;
                $this->pagination->total_rows = $page_total_rows;
                $this->pagination->get_template();
                $this->pagination->build();
            }
            
            // update offset
            $offset = $offset + $this->pagination->offset;
        }
        
        // {absolute_count} - absolute count to the ordered/sorted items
        $i=0;
        foreach($list as $key => &$value)
        {
            $i++;
            $value['absolute_count'] = $i;
            
            // {prefix:absolute_count}
            if ( ! is_null($prefix))
            {
                $value[$prefix.':absolute_count'] = $i;
            }
        }
        
        // {absolute_results} - record the total number of list rows
        $list_markers['absolute_results'] = $absolute_results;
        
        // slice array depending on limit/offset
        if ($limit && $offset)
        {
            $list = array_slice($list, $offset, $limit);
        }
        elseif ($limit)
        {
            $list = array_slice($list, 0, $limit);
        }
        elseif ($offset)
        {
            $list = array_slice($list, $offset);
        }
        
        // prefixes
        if ( ! is_null($prefix))
        {
            // {prefix:absolute_results}
            $list_markers[$prefix.':absolute_results'] = $list_markers['absolute_results'];
            
            // {prefix:total_results}
            $list_markers[$prefix.':total_results'] = count($list);
        }   
        
        if (count($list) > 0)
        { 
            // track use of one or more elements
            if ($track)
            {
                if ( ! isset(self::$_cache['track']))
                {
                    self::$_cache['track'] = array();
                }

                $track = explode('|', $track);

                foreach($track as $t) 
                {
                    if ( ! isset(self::$_cache['track'][$t]))
                    {
                        self::$_cache['track'][$t] = array();
                    }

                    foreach($list as $key => $v)
                    {   
                        if (isset($v[$t]))
                        {
                            self::$_cache['track'][$t][] = $v[$t];
                        }
                    }
                    // ensure the tracked values are always unique
                    self::$_cache['track'][$t] = array_unique(self::$_cache['track'][$t]);
                }
            }

            if ( ! is_null($prefix))
            {
                // {prefix:count}
                $i=0;
                foreach($list as $key => &$v)
                {
                    $i++;
                    $v[$prefix.':count'] = $i;
                }
                
                // {prefix:switch = ""}
                if (strpos(ee()->TMPL->tagdata, LD.$prefix.':switch') !== FALSE)
                {
                    ee()->TMPL->tagdata = str_replace(LD.$prefix.':switch', LD.'switch', ee()->TMPL->tagdata);
                }   
            }
            
            // disable backspace param to stop parse_variables() doing it automatically
            // because it can potentially break unparsed conditionals / tags etc in the list
            $backspace = ee()->TMPL->fetch_param('backspace', FALSE);
            ee()->TMPL->tagparams['backspace'] = FALSE;

            // prep {if IN ()}...{/if} conditionals
            if ($this->parse_if_in)
            {
                // prefixed ifs? We have to hide them in EE 2.9+ if this tagdata is in the root template
                if ( ! is_null($prefix))
                {
                    ee()->TMPL->tagdata = str_replace(LD.$prefix.':if', LD.'if', ee()->TMPL->tagdata);
                    ee()->TMPL->tagdata = str_replace(LD.'/'.$prefix.':if'.RD, LD.'/if'.RD, ee()->TMPL->tagdata);
                }

                ee()->TMPL->tagdata = $this->_prep_in_conditionals(ee()->TMPL->tagdata);
            }
            
            // Replace into template.
            //
            // KNOWN BUG:
            // TMPL::parse_variables() runs functions::preps conditionals() which is buggy with advanced conditionals
            // that reference *external* variables (such as global variables, segment variables).
            // E.g. say you have a list var '{tel}' with value '123' and global var '{pg_tel}' with value '456'
            // {if pg_tel OR pg_fax} is changed to {if pg_"123" OR pg_fax} and will throw an error :( 
            //
            // WORKAROUND: 
            // use the prefix="" param for local list vars when you need to reference external
            // variables inside the get_list tag pair which have names that could collide.

            $list_html = ee()->TMPL->parse_variables(ee()->TMPL->tagdata, $list);
        
            // restore original backspace parameter
            ee()->TMPL->tagparams['backspace'] = $backspace;
        
            // parse other markers
            $list_html = ee()->TMPL->parse_variables_row($list_html, $list_markers);
            
            // render pagination
            if ($paginate)
            {
                $list_html = $this->pagination->render($list_html);
            }
        
            // now apply final output transformations / parsing
            return $this->_parse_output($list_html, NULL, $filter, $default);
        }
        else
        {
            return $this->_no_results();
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve the item count for a given list
     *
     * @access public
     * @return string 
     */
    public function list_count()
    {           
        // retrieve the list array
        $list = $this->rebuild_list();

        // return 0 if this variable has no value
        if ($list == '') return '0';

        $count = count($list);
        return "$count"; // make sure we return a string
    }
    
    // ---------------------------------------------------------
    
    /**
     * Restore values for a given bundle
     *
     * @access public
     * @param bool set the bundled variables into Stash variables?
     * @return string
     */
    public function get_bundle($set=TRUE)
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:get_bundle name="contact_form" context="@" limit="5"}
            {contact_name}
        {/exp:stash:get_bundle}
        --------------------------------------------------------- */
        $out = ee()->TMPL->tagdata;
        
        if ( !! $bundle = ee()->TMPL->fetch_param('name', FALSE) )
        {
            
            // get the bundle id, cache to memory for efficient reuse later
            $bundle_id = ee()->stash_model->get_bundle_by_name($bundle);
            
            // does this bundle already exist?
            if ( $bundle_id )
            {           
                $bundle_array = array();
                $tpl = ee()->TMPL->tagdata;
                $this->bundle_id = $bundle_id;
                
                // get params
                $unique = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('unique', 'yes'));
                $index  = ee()->TMPL->fetch_param('index', NULL);  
                $context = ee()->TMPL->fetch_param('context', NULL);
                $scope = strtolower(ee()->TMPL->fetch_param('scope', 'user')); // user|site

                // if this is a unique bundle, restore the bundled variables to static bundles array
                if ($unique || ! is_null($index))
                {       
                    if ( $index !== NULL && $index > 0)
                    {
                        $bundle .= '_'.$index;
                        ee()->TMPL->tagparams['name'] = $bundle;
                    }
                    
                    // get bundle var
                    $bundle_entry_key = $bundle;
                    if ($bundle !== NULL && count( explode(':', $bundle)) == 1 )
                    {
                        $bundle_entry_key = $context . ':' . $bundle;
                    }
                    $session_id = $scope === 'user' ? $this->_session_id : '';
                    $bundle_entry_key = $this->_parse_context($bundle_entry_key);
                    
                    // look for our key
                    if ( $bundle_value = ee()->stash_model->get_key(
                        $bundle_entry_key, 
                        $this->bundle_id,
                        $session_id, 
                        $this->site_id
                    ))
                    {   
                        $bundle_array[0] = unserialize($bundle_value);
                        
                        foreach ($bundle_array[0] as $key => $val)
                        {
                            self::$bundles[$bundle][$key] = $val;
                        }   
                    }   
                }
                else
                {
                    // FUTURE FEATURE: get all entries for a bundle with *multiple* rows    
                }
                
                // replace into template
                if ( ! empty($tpl))
                {
                    // take care of any unparsed current context pointers '@'
                    if ( ! is_null($context))
                    {
                        $tpl = str_replace(LD.'@:', LD.$context.':', $tpl);
                    }
                    
                    if ( ! empty($bundle_array))
                    {
                        $out = '';
                        foreach($bundle_array as $vars)
                        {   
                            $out .= ee()->functions->var_swap($tpl, $vars);
                        
                            // set variables
                            if ($set)
                            {
                                foreach($vars as $name => $value)
                                {
                                    ee()->TMPL->tagparams['name'] = $name;
                                    ee()->TMPL->tagparams['type'] = 'variable';
                                    ee()->TMPL->tagdata = $value;
                                    $this->replace = TRUE;
                                
                                    $this->_run_tag('set', array('name', 'type', 'scope', 'context'));
                                }
                            }
                        }
                    }
                    
                    // prep 'IN' conditionals if the retreived var is a delimited string
                    if ($this->parse_if_in)
                    {
                        $out = $this->_prep_in_conditionals($out);
                    }
                }
                
                ee()->TMPL->log_item("Stash: RETRIEVED bundle ".$bundle);
            }
        }
            
        return $out;    
    }
    
    // ---------------------------------------------------------
    
    /**
     * Set values into a bundle
     *
     * @access public
     * @return void 
     */
    public function set_bundle()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:set_bundle name="contact_form"}
        --------------------------------------------------------- */
        
        if ( !! $bundle = ee()->TMPL->fetch_param('name', FALSE) )
        {           
            if ( isset(self::$bundles[$bundle]))
            {
                // get params
                $bundle_label = ee()->TMPL->fetch_param('label', $bundle);
                $unique = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('unique', 'yes'));
                $bundle_entry_key = $bundle_entry_label = $bundle;
                
                // get the bundle id
                $bundle_id = ee()->stash_model->get_bundle_by_name($bundle);
                
                // does this bundle already exist? Let's try to get it's id
                if ( ! $bundle_id )
                {
                    // doesn't exist, let's create it
                    $bundle_id = ee()->stash_model->insert_bundle(
                        $bundle,
                        $bundle_label
                    );      
                }
                elseif ( ! $unique)
                {
                    // bundle exists, but do we want more than one entry per bundle?
                    $entry_count = ee()->stash_model->bundle_entry_count($bundle_id, $this->site_id);
                    if ($entry_count > 0)
                    {
                        $bundle_entry_key .= '_'.$entry_count;
                        $bundle_entry_label = $bundle_entry_key;
                    }
                }
                
                // stash the data under a single key
                ee()->TMPL->tagparams['name']  = $bundle_entry_key;
                ee()->TMPL->tagparams['label'] = $bundle_entry_label;
                ee()->TMPL->tagparams['save']  = 'yes';
                ee()->TMPL->tagparams['scope'] = 'user';
                ee()->TMPL->tagdata = serialize(self::$bundles[$bundle]);
                $this->bundle_id = $bundle_id;
                
                unset(self::$bundles[$bundle]);
                return $this->set();    
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Bundle up a collection of variables and save in the database
     *
     * @access public
     * @param array $params 
     * @param array $dynamic 
     * @return void 
     */
    public function bundle($params = array(), $dynamic = array())
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:bundle name="contact_form" context="@" unique="no" type="snippet" refresh="10"}
            {exp:stash:get dynamic="yes" name="orderby" output="no" default="persons_last_name" match="#^[a-zA-Z0-9_-]+$#"}
            {exp:stash:get dynamic="yes" name="sort" output="no" default="asc" match="#^asc$|^desc$#"}
            {exp:stash:get dynamic="yes" name="filter" output="no" default="" match="#^[a-zA-Z0-9_-]+$#"}
            {exp:stash:get dynamic="yes" name="in" output="no" default="" match="#^[a-zA-Z0-9_-]+$#"}
            {exp:stash:get dynamic="yes" name="field" output="no" match="#^[a-zA-Z0-9_-]+$#" default="persons_last_name"}
        {/exp:stash:bundle}
        --------------------------------------------------------- */
        
        // is this method being called statically from PHP?
        if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
        {
            // as this function is called statically, 
            // we need to get an instance of this object and run get()
            $self = new self(); 
            
            // set parameters
            ee()->TMPL->tagparams = $params;
            
            // convert tagdata array
            if ( is_array($dynamic))
            {
                ee()->TMPL->tagdata = '';
                
                foreach ($dynamic as $name => $options)
                {
                    ee()->TMPL->tagdata .= LD.'exp:stash:get dynamic="yes" name="'.$name.'"';
                    foreach ($options as $option => $value)
                    {
                        ee()->TMPL->tagdata .= ' '.$option.'="'.$value.'"';
                    }
                    ee()->TMPL->tagdata .= RD;
                }
            }
            else
            {
                ee()->TMPL->tagdata = $dynamic;
            }
        
            return $self->bundle();
        }
        
        if ( !! $bundle = ee()->TMPL->fetch_param('name', FALSE) )
        {
            // build a string of parameters to inject into nested stash tags
            $context = ee()->TMPL->fetch_param('context', NULL);
            $params = 'bundle="' . $bundle . '" scope="local"';
            
            if ($context !== NULL )
            {
                $params .=  ' context="'.$context.'"';
            }
            
            // add params to nested tags
            ee()->TMPL->tagdata = preg_replace( '/('.LD.'exp:stash:get|'.LD.'exp:stash:set)/i', '$1 '.$params, ee()->TMPL->tagdata);
            
            // get existing values from bundle
            $this->get_bundle(FALSE);
            
            // parse stash tags in the bundle
            $this->_parse_sub_template();
            
            // save the bundle values
            $this->set_bundle();
        }
    }
    
    // ----------------------------------------------------------

    /**
     * Embed a Stash template file in the current template
     *
     * @access public
     * @return string 
     */
    public function embed()
    {   
        /* Sample use
        ---------------------------------------------------------
        {stash:embed name="my_template" 
            context="my_template_folder" 
            process="start"
            stash:another_var1="value 1"
            stash:another_var2="value 2"
        }
        
        Alternative sytax:
        {stash:embed:name} or
        {stash:embed:context:name}
        --------------------------------------------------------- */
            
        // mandatory parameter values for template files
        ee()->TMPL->tagparams['file']                = 'yes';
        ee()->TMPL->tagparams['embed_vars']          = array();

        // parse="yes"?
        $this->set_parse_params();

        // default parameter values
        ee()->TMPL->tagparams['save']                = ee()->TMPL->fetch_param('save', 'yes');
        ee()->TMPL->tagparams['scope']               = ee()->TMPL->fetch_param('scope', 'site');
        ee()->TMPL->tagparams['parse_tags']          = ee()->TMPL->fetch_param('parse_tags', 'yes');
        ee()->TMPL->tagparams['parse_vars']          = ee()->TMPL->fetch_param('parse_vars', 'yes');
        ee()->TMPL->tagparams['parse_conditionals']  = ee()->TMPL->fetch_param('parse_conditionals', 'yes');

        // name and context passed in tagparts?
        if (isset(ee()->TMPL->tagparts[3]))
        {   
            ee()->TMPL->tagparams['context'] = ee()->TMPL->tagparts[2];
            ee()->TMPL->tagparams['name'] = ee()->TMPL->tagparts[3];  
        }
        elseif(isset(ee()->TMPL->tagparts[2]))
        {
            ee()->TMPL->tagparams['name'] = ee()->TMPL->tagparts[2];
        }

        // default to processing embeds at end
        ee()->TMPL->tagparams['process'] = ee()->TMPL->fetch_param('process', 'end');

        // is this a static template?
        if ( ee()->TMPL->tagparams['process'] !== 'static')
        {   
            // non-static templates are assigned to the template bundle by default
            ee()->TMPL->tagparams['bundle'] = ee()->TMPL->fetch_param('bundle', 'template');

            // by default, parse the template when it is retrieved from the database (like a standard EE embed)
            ee()->TMPL->tagparams['parse_stage'] = ee()->TMPL->fetch_param('parse_stage', 'get');
        }
        else
        {
            // mandatory params for static templates
            ee()->TMPL->tagparams['bundle']  = 'static'; // must be assigned to the static bundle
            ee()->TMPL->tagparams['process'] = 'end';
            ee()->TMPL->tagparams['context'] = "@URI"; // must be in the context of current URI
            ee()->TMPL->tagparams['parse_stage'] = "set"; // static templates must be pre-parsed
            ee()->TMPL->tagparams['refresh'] = "0"; // static templates can never expire

            // as this is the full rendered output of a template, check that we should really be saving it
            if ( ! $this->_is_cacheable())
            {
                ee()->TMPL->tagparams['save'] = 'no';
                self::$_nocache = FALSE; // remove {stash:nocache} pairs
            }
        }
        
        // set default parameter values for template files
        
        // set a parse depth of 4
        ee()->TMPL->tagparams['parse_depth'] = ee()->TMPL->fetch_param('parse_depth', 4);
        
        // don't replace the variable by default (only read from file once)
        // note: file syncing can be forced by setting stash_file_sync = TRUE in config
        ee()->TMPL->tagparams['replace'] = ee()->TMPL->fetch_param('replace', 'no');
        
        // set priority to 0 by default, so that embeds come before post-processed variables
        ee()->TMPL->tagparams['priority'] = ee()->TMPL->fetch_param('priority', '0');
        
        // initialise?
        $init = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('init', 'yes'));
        
        // re-initialise parameters, unless disabled by init parameter
        if ($init)
        {
            $this->init();
        }
        else
        {
            $this->process = 'inline';
        }
        
        // save stash embed vars passed as parameters in the form stash:my_var which we'll
        // inject later into the stash array for replacement, so remove the stash: prefix
        $params = ee()->TMPL->tagparams;

        foreach ($params as $key => $val)
        {
            if (strncmp($key, 'stash:', 6) == 0)
            {
                ee()->TMPL->tagparams['embed_vars'][substr($key, 6)] = $val;
            }
        }

        // permitted parameters for embeds
        $reserved_vars = array(
            'name', 
            'context', 
            'scope', 
            'file', 
            'file_name',
            'parse_stage',
            'save', 
            'refresh',
            'replace',
            'parse_tags',
            'parse_depth',
            'parse_vars',
            'parse_conditionals',
            'process',
            'priority',
            'output',
            'embed_vars',
            'bundle',
            'prefix',
            'trim',
            'strip_tags',
            'strip_curly_braces',
            'strip_unparsed',
            'compress',
            'backspace',
            'strip',
        );
    
        return $this->_run_tag('get', $reserved_vars);
    }


    // ----------------------------------------------------------

    /**
     * Cache tagdata to db and link it to the current URL context
     *
     * @access public
     * @return string 
     */
    public function cache()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:cache}
        ...
        {/exp:stash:cache}
        */
       
        // process as a static cache?
        if ( ee()->TMPL->fetch_param('process') == 'static')
        {   
            return $this->static_cache(ee()->TMPL->tagdata);
        }

        // Is this page really cacheable? (Note: allow all request types to be cached)
        if ( ! $this->_is_cacheable(FALSE))
        {
            self::$_nocache = FALSE; // remove {stash:nocache} pairs
            ee()->TMPL->tagparams['replace'] = 'yes';
            ee()->TMPL->tagparams['save'] = 'no'; // disable caching
        }
        else
        {
             ee()->TMPL->tagparams['save'] = 'yes';
        }

        // Unprefix common variables in wrapped tags
        if($unprefix = ee()->TMPL->fetch_param('unprefix'))
        {
            ee()->TMPL->tagdata = $this->_un_prefix($unprefix, ee()->TMPL->tagdata);
        }

        // default name for cached items is 'cache'
        ee()->TMPL->tagparams['name'] = ee()->TMPL->fetch_param('name', 'cache'); 

        // cached items are saved to the template bundle by default, allow this to be overridden
        ee()->TMPL->tagparams['bundle'] = ee()->TMPL->fetch_param('bundle', 'template'); 

        // by default, parse on both set and get (i.e. so partial caching is possible)
        ee()->TMPL->tagparams['parse_stage'] = ee()->TMPL->fetch_param('parse_stage', 'both');

        // key_name format for cached items is @URI:context:name, where @URI is the current page URI
        // thus context is always @URI, and name must be set to context:name
        if ( $context = ee()->TMPL->fetch_param('context', FALSE))
        {
            ee()->TMPL->tagparams['name'] = $this->_parse_context($context . ':') . ee()->TMPL->tagparams['name'];
        }

        // context parameter MUST be set to the page URI pointer
        ee()->TMPL->tagparams['context'] = '@URI';

        // set a default parse depth of 4
        ee()->TMPL->tagparams['parse_depth'] = ee()->TMPL->fetch_param('parse_depth', 4);
        
        // don't replace the variable by default
        ee()->TMPL->tagparams['replace'] = ee()->TMPL->fetch_param('replace', 'no');

        // set a default refresh of 0 (never)
        ee()->TMPL->tagparams['refresh'] = ee()->TMPL->fetch_param('refresh', 0);
        
        // mandatory parameter values for cached items
        ee()->TMPL->tagparams['scope']               = 'site';
        ee()->TMPL->tagparams['parse_tags']          = 'yes';
        ee()->TMPL->tagparams['parse_vars']          = 'yes';
        ee()->TMPL->tagparams['parse_conditionals']  = 'yes';
        ee()->TMPL->tagparams['output']              = 'yes';
        $this->process = 'end';

        // re-initialise Stash with the new params
        $this->init();

        // permitted parameters for cache
        $reserved_vars = array(
            'name', 
            'context', 
            'scope', 
            'parse_stage',
            'save', 
            'refresh',
            'replace',
            'parse_tags',
            'parse_depth',
            'parse_vars',
            'parse_conditionals',
            'output',
            'bundle',
            'prefix',
            'trim',
            'strip_tags',
            'strip_curly_braces',
            'strip_unparsed',
            'compress',
            'backspace',
            'strip',
        );
        
        // cache / retreive the variables
        $this->_run_tag('set', $reserved_vars);

        // Is partially cached content possible? We'll need to make sure it's parsed before returning to the template
        if (ee()->TMPL->tagparams['parse_stage'] == 'both' || ee()->TMPL->tagparams['parse_stage'] == 'get')
        {
            $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
        }

        return ee()->TMPL->tagdata;
    }

    // ----------------------------------------------------------

    /**
     * Cache a template to file and link it to the current URL context
     *
     * @access public
     * @param  string $output additional tagdata to return to the template along with the placeholder
     * @return string 
     */
    public function static_cache($output='')
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:static} or {exp:stash:static_cache}
        */

        // default name for static cached items is 'static'
        ee()->TMPL->tagparams['name'] = ee()->TMPL->fetch_param('name', 'static'); 

        // format for key_name for cached items is @URI:context:name, where @URI is the current page URI
        // thus context is always @URI, and name must be set to context:name
        if ( $context = ee()->TMPL->fetch_param('context', FALSE))
        {
            if ($context !== '@URI')
            {
                ee()->TMPL->tagparams['name'] = $context . ':' . ee()->TMPL->tagparams['name'];
            }
        }

        // Allow @URI to be overridden...
        $uri = ee()->TMPL->fetch_param('uri', '@URI');

        // parse cache key, making sure query strings are excluded from the @URI
        ee()->TMPL->tagparams['name'] = $this->_parse_context($uri . ':' . ee()->TMPL->tagparams['name'], TRUE);


        $this->process = 'end';
        $this->priority = '999999'; //  should be the last thing post-processed (by Stash)

        // has the tag been used as a tag pair? If so, we'll parse the tagdata to remove {stash:nocache} pairs
        if (ee()->TMPL->tagdata)
        {
            // parse the tagdata
            self::$_nocache = FALSE; // remove {stash:nocache} pairs
            $output = $this->parse();
        }

        if ($out = $this->_post_parse('save_output')) return $out . $output;
    }


    // ----------------------------------------------------------

    /**
     * Save the final rendered template output to a static file
     *
     * @access public
     * @param  string $output the rendered template
     * @return string 
     */
    public function save_output($output='')
    { 
        // mandatory parameter values for cached output
        ee()->TMPL->tagparams['context']   = NULL;
        ee()->TMPL->tagparams['scope']     = 'site';
        ee()->TMPL->tagparams['replace']   = "no";  // static cached items cannot be replaced
        ee()->TMPL->tagparams['bundle']    = 'static'; // cached pages in the static bundle are saved to file automatically by the model

        // optional parameters
        ee()->TMPL->tagparams['refresh']   = ee()->TMPL->fetch_param('refresh', 0); // by default static cached items won't expire

        // bundle determines the cache driver
        $this->bundle_id = ee()->stash_model->get_bundle_by_name(ee()->TMPL->tagparams['bundle']);

        // set the entire template data as the tagdata, removing the placeholder for this tag from the output saved to file
        ee()->TMPL->tagdata = $output;

        // as this is the full rendered output of a template, check that we should really be saving it
        if ( ! $this->_is_cacheable())
        {
            ee()->TMPL->tagparams['save'] = 'no';
        }
        else
        {
            ee()->TMPL->tagparams['save'] = 'yes';
        }
        
        // permitted parameters for cached
        $reserved_vars = array(
            'name', 
            'context', 
            'scope', 
            'save', 
            'refresh',
            'replace',
            'bundle',
            'trim',
            'strip_tags',
            'strip_curly_braces',
            'strip_unparsed',
            'compress',
            'backspace',
            'strip',
        );
    
        $this->_run_tag('set', $reserved_vars);

        return ee()->TMPL->tagdata;
    }

    // ---------------------------------------------------------
    
    /**
     * Output the 404 template with the correct header and exit
     *
     * @access public
     * @return string 
     */
    public function not_found()
    {   
        // try to prevent recursion
        if ( ee()->output->out_type == "404") 
        {
            return;
        }

        $url = FALSE;
        $template = explode('/', ee()->config->item('site_404'));

        if (isset($template[1]))
        {
            // build an absolute URL to the 404 template
            $url = ee()->functions->create_url($template[0].'/'.$template[1]);
        }

        // We'll use cURL to grab the rendered 404 template
        // The template MUST be publicly accessible without being logged in
        if ($url 
            && ee()->config->item('is_system_on') !== 'n'
            && is_callable('curl_init'))
        {     
            // set header
            ee()->config->set_item('send_headers', FALSE); // trick EE into not sending a 200
            ee()->output->set_status_header('404');

            // grab the rendered 404 page
            $ch = curl_init();
            
            // set the url
            curl_setopt($ch, CURLOPT_URL, $url);

            // return it direct, don't print it out
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            
            // this connection will timeout in 10 seconds
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            // don't validate SSL certs
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
            $result = @curl_exec($ch); 
                
            if (curl_errno($ch)) 
            {   
                ee()->TMPL->log_item(curl_error($ch));
                curl_close($ch);
            } 
            else 
            {
                die($result);
            }
        }

        // if cURL fails or system is off, fallback to a redirect
        if ($url)
        {
            ee()->functions->redirect($url, FALSE, '404');
        }
        else 
        {
            ee()->TMPL->log_item('Stash: 404 template is not configured. Please select a 404 template in Design > Templates > Global Preferences.');
        }
    }

    // ---------------------------------------------------------    

    /**
     * Check to see if a template (not a fragment) is suitable for caching
     *
     * @access public
     * @param  boolean $check_request_type
     * @return string 
     */
    private function _is_cacheable($check_request_type=TRUE)
    {
        // Check if we should cache this URI
        if ($check_request_type)
        {
            if ($_SERVER['REQUEST_METHOD'] == 'POST'     // … POST request
                || ee()->input->get('ACT')          // … ACT request
                || ee()->input->get('css')          // … css request
                || ee()->input->get('URL')          // … URL request
            )
            {
                return FALSE;
            }
        }

        // has caching been deliberately disabled?
        if ( FALSE === (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('save', 'yes')) )
        {
            return FALSE;
        }

        // logged_in_only: only cache if the page visitor is logged in
        if ( (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('logged_in_only')) )
        {
            if (ee()->session->userdata['member_id'] == 0)
            {
                return FALSE; // don't cache
            }
        }

        // logged_out_only: only cache if the page visitor is logged out
        if ((bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('logged_out_only')) )
        {
            if (ee()->session->userdata['member_id'] != 0)
            {
                return FALSE; // don't cache
            }
        }
        
        return TRUE;
    }


    // ----------------------------------------------------------

    /**
     * Tagb for cleaning up specific placeholders before final output
     *
     * @access public
     * @return string 
     */
    public function finish()
    {
        /* Sample use
        ---------------------------------------------------------
        {exp:stash:finish nocache="no" compress="yes"}
        */

        // disable nocache for all template data parsed after this point?
        self::$_nocache = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('nocache', 'y'));

        $this->process = 'end';
        $this->priority = '999998'; //  should be the *second to last* thing post-processed (by Stash)

        if ($out = $this->_post_parse('final_output')) return $out;
    }

    // ----------------------------------------------------------

    /**
     * Final parsing/cleanup of template tagdata before output
     *
     * @access public
     * @return string 
     */
    public function final_output($output='')
    {   
        // is nocache disabled?
        if ( ! self::$_nocache)
        {
            // yes - let's remove any {[prefix]:nocache} tags from the final output
            $strip = ee()->TMPL->fetch_param('strip', FALSE);

            if ($strip)
            {
                $strip = explode('|', $strip);
            }
            else
            {
                $strip = array();
            }

            foreach(self::$_nocache_prefixes as $prefix)
            {
                $strip[] = $prefix . $this->_nocache_suffix;
            }

            ee()->TMPL->tagparams['strip'] = implode('|', $strip);
        }

        // Do string transformations
        $output = $this->_clean_string($output);

        // set as template tagdata
        ee()->TMPL->tagdata = $output;

        // remove the placeholder from the output
        return ee()->TMPL->tagdata;
    }

    
    // ---------------------------------------------------------    
    
    /**
     * Parse tagdata
     *
     * @param  array $params an array of key => value pairs representing tag parameters
     * @param  string $value string to parse, defaults to template tagdata
     * @access public
     * @return string 
     */
    public function parse($params = array(), $value=NULL)
    {       
        // is this method being called directly?
        if ( func_num_args() > 0)
        {   
            if ( !(isset($this) && get_class($this) == __CLASS__))
            {
                return self::_api_static_call(__FUNCTION__, $params, '', '', $value);
            }
            else
            {
                return $this->_api_call(__FUNCTION__, $params, '', '', $value);
            }
        }

        // parse="yes"?
        $this->set_parse_params();

        // default parameter values
        ee()->TMPL->tagparams['parse_tags']          = ee()->TMPL->fetch_param('parse_tags', 'yes');
        ee()->TMPL->tagparams['parse_vars']          = ee()->TMPL->fetch_param('parse_vars', 'yes');
        ee()->TMPL->tagparams['parse_conditionals']  = ee()->TMPL->fetch_param('parse_conditionals', 'yes');
        ee()->TMPL->tagparams['parse_depth']         = ee()->TMPL->fetch_param('parse_depth', 4);
        
        // postpone tag processing?
        if ( $this->process !== 'inline') 
        {   
            if ($out = $this->_post_parse(__FUNCTION__)) return $out;
        }

        // re-initialise Stash with the new default params
        $this->init();

        // Unprefix common variables in wrapped tags
        if($unprefix = ee()->TMPL->fetch_param('unprefix'))
        {
            ee()->TMPL->tagdata = $this->_un_prefix($unprefix, ee()->TMPL->tagdata);
        }
        
        // do the business
        $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
        
        // output the parsed template data?
        $output = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('output', 'yes'));

        if ($output)
        {
            return ee()->TMPL->tagdata;
        }
    }


    // ---------------------------------------------------------
        
    /**
     * Unset variable(s) in the current session, optionally flush from db
     *
     * @access public
     * @param  mixed     $params The name of the variable to unset, or an array of key => value pairs
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return void 
     */
    public function destroy($params=array(), $type='variable', $scope='user')
    {
        // is this method being called directly?
        if ( func_num_args() > 0)
        {   
            if ( !(isset($this) && get_class($this) == __CLASS__))
            {
                return self::_api_static_call(__FUNCTION__, $params, $type, $scope);
            }
            else
            {
                return $this->_api_call(__FUNCTION__, $params, $type, $scope);
            }
        }
        
        // register params
        $name = ee()->TMPL->fetch_param('name', FALSE);        
        $context = ee()->TMPL->fetch_param('context', NULL);
        $scope = strtolower(ee()->TMPL->fetch_param('scope', $this->default_scope));
        $flush_cache = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('flush_cache', 'yes'));
        $bundle = ee()->TMPL->fetch_param('bundle', NULL);
        $bundle_id = ee()->TMPL->fetch_param('bundle_id', FALSE);
        
        // narrow the scope to user session?
        $session_id = NULL;

        if ($scope === 'user')
        {
            $session_id = $this->_session_id;
        }
        elseif($scope === 'site')
        {
            $session_id = '_global';
        }

        // named bundle?
        if ( ! is_null($bundle) && ! $bundle_id)
        {
            $bundle_id = ee()->stash_model->get_bundle_by_name($bundle);
        }
        
        // unset a single variable, or multiple variables that match a regex
        if ($name)
        {   
            if (preg_match('/^#(.*)#$/', $name))
            {   
                // remove matching variable keys from the session
                foreach($this->_stash as $var_key => $var_val)
                {
                    if (preg_match($name, $var_key))
                    {
                        unset($this->_stash[$var_key]);
                    }
                }

                // remove from db cache?
                if ($flush_cache && $scope !== 'local')
                {
                    // delete variables with key_names that match the regex
                    ee()->stash_model->delete_matching_keys(
                        $bundle_id,
                        $session_id, 
                        $this->site_id,
                        trim($name, '#'),
                        $this->invalidation_period
                    );
                }
            }
            else
            {
                // a named variable
                if ($context !== NULL && count( explode(':', $name)) == 1 )
                {
                    $name = $context . ':' . $name;
                }
                
                // remove from session
                if ( isset($this->_stash[$name]))
                {
                    unset($this->_stash[$name]);
                }
                
                // remove from db cache?
                if ($flush_cache && $scope !== 'local')
                {
                    // replace '@' placeholders with the current context
                    $stash_key = $this->_parse_context($name);

                    // as we're deleting a specific key, the bundle_id is required
                    $bundle_id = $bundle_id ? $bundle_id : $this->bundle_id;
                    
                    ee()->stash_model->delete_key(
                        $stash_key, 
                        $bundle_id,
                        $session_id, 
                        $this->site_id,
                        $this->invalidation_period
                    );
                }
            }
        }   
        elseif($scope === 'user' || $scope === 'site' || $scope === 'all')
        {
            // unset ALL user-scoped variables in the current process
            $this->_stash = array();
            
            // remove from cache
            if ($flush_cache)
            {
                ee()->stash_model->delete_matching_keys(
                    $bundle_id,
                    $session_id, 
                    $this->site_id,
                    NULL,
                    $this->invalidation_period
                );
            }
        }
    }


    // ---------------------------------------------------------
    
    /**
     * Flush the variables database cache for the current site (Super Admins only)
     *
     * @access public
     * @return string 
     */
    public function flush_cache()
    {
        if (ee()->session->userdata['group_title'] == "Super Admins")
        {
            if ( ee()->stash_model->flush_cache($this->site_id))
            {
                return ee()->lang->line('cache_flush_success');
            }
        }
        else
        {
            // not authorised
            ee()->output->show_user_error('general', ee()->lang->line('not_authorized'));
        }
    }

    /*
    ================================================================
    Utility methods
    ================================================================
    */
    
    /**
     * Match a regex against a string or array of strings
     *
     * @access private
     * @param string $match A regular expression
     * @param string/array $against array of strings to match regex against
     * @return bool
     */
    private function _matches($match, $against)
    {
        $is_match = TRUE;
        $match = ee('Security/XSS')->entity_decode($match);

        if ( ! is_array($against)) 
        {
            $against = array($against);
        }
        else
        {
            // remove null values
            $against = array_filter($against, 'strlen');
        }
        
        // check every value in the array matches
        foreach($against as $part)
        {
            // convert placeholder null to an empty string before comparing
            if ($part === $this->_list_null)
            {
                $part = '';
            }
            ee()->TMPL->log_item('Stash: MATCH '. $match . ' AGAINST ' . $part);
            
            if ( ! preg_match($match, $part))
            {
                $is_match = FALSE;
                break;
            }
        }
        return $is_match;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve and rebuild list, or optionally part of a list
     *
     * @access public
     * @param  mixed     $params The name of the variable to retrieve, or an array of key => value pairs
     * @param  string    $type  The type of variable
     * @param  string    $scope The scope of the variable
     * @return array
     */
    public function rebuild_list($params='', $type='variable', $scope='user')
    {
        // is this method being called directly?
        if ( func_num_args() > 0)
        {   
            if ( !(isset($this) && get_class($this) == __CLASS__))
            {
                return self::_api_static_call(__FUNCTION__, $params, $type, $scope);
            }
            else
            {
                return $this->_api_call(__FUNCTION__, $params, $type, $scope);
            }
        }

        $sort       = strtolower(ee()->TMPL->fetch_param('sort', FALSE));
        $sort_type  = strtolower(ee()->TMPL->fetch_param('sort_type', FALSE)); // string || integer || lowercase
        $orderby    = ee()->TMPL->fetch_param('orderby', FALSE);
        $match      = ee()->TMPL->fetch_param('match', NULL); // regular expression to each list item against
        $against    = ee()->TMPL->fetch_param('against', NULL); // array key to test $match against
        $unique     = ee()->TMPL->fetch_param('unique', NULL);
        $slice      = ee()->TMPL->fetch_param('slice', NULL); // e.g. "0, 2" - slice the list array before order/sort/limit
        $in         = ee()->TMPL->fetch_param('in',  FALSE); // compare column against a tracked value, e.g. list_column:tracked_column, and include if it matches
        $not_in     = ee()->TMPL->fetch_param('not_in',  FALSE); // compare column against a tracked value, and exclude if it matches
        
        // make sure any parsing is done AFTER the list has been replaced in to the template 
        // not when it's still a serialized array
        $this->parse_complete = TRUE;
        
        // run get() with a safe list of parameters
        $list = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
        
        // reenable parsing
        $this->parse_complete = FALSE;

        if ($list !== '' && $list !== NULL)
        {
            // explode the list
            $list = explode( $this->_list_delimiter, $list);
        
            foreach($list as $key => &$value)
            {
                $value = $this->_list_row_explode($value);
            }
            unset($value);

            // apply order/sort
            if ($orderby)
            {
                if ($orderby == 'random')
                {
                    // shuffle list order
                    shuffle($list);
                }
                elseif (strncmp($orderby, 'random:', 7) == 0)
                {
                    // shuffle one or more keys in the list, but leave the overall list order unchanged
                    $keys_to_shuffle = explode(',', substr($orderby, 7));
                    foreach($keys_to_shuffle as $key_shuffle)
                    {
                        $list = $this->shuffle_list_key($list, $key_shuffle);
                    }
                }
                else
                {
                    // here be dragons (array_multisort)
                    $orderby   = explode('|', preg_replace('#\s+#', '', $orderby));
                    $sort      = explode('|', preg_replace('#\s+#', '', $sort));
                    $sort_type = explode('|', preg_replace('#\s+#', '', $sort_type));

                    // make columns out of rows needed for orderby
                    $columns = array();
                    foreach ($list as $key => $row)
                    {
                        foreach ($orderby as $name)
                        {
                            if ( isset($list[$key][$name]) )
                            {
                                $columns[$name][$key] =& $list[$key][$name];
                            }
                            else
                            {
                                $columns[$name][$key] = null;
                            }
                        }
                    }

                    // create function arguments for multisort
                    $args = array();
                    foreach ($orderby as $i => $name)
                    {
                        $args[] =& $columns[$name]; // column reference 

                        // SORT_ASC is default, only change if desc
                        if (isset($sort[$i]) && $sort[$i]=="desc")
                        {
                            $args[] = SORT_DESC;
                        }

                        // types string, integer, lowercase
                        if (isset($sort_type[$i]))
                        {
                            switch ($sort_type[$i])
                            {
                                case 'string':
                                    $args[] = SORT_STRING;
                                    break;
                                case 'integer': case 'numeric':
                                    $args[] = SORT_NUMERIC;
                                    break;
                                case 'lowercase':
                                    $columns[$name] = array_map('strtolower', $columns[$name]);
                                    $args[] = SORT_STRING;  
                                    break;
                                case 'normalize':
                                    $columns[$name] = array_map(array($this, '_normalize'), $columns[$name]);
                                    $args[] = SORT_STRING;
                                    break;
                                default:
                                    // $args[] = SORT_REGULAR;
                                    break;
                            }
                        }
                    }
                    // last argument, array to sort
                    $args[] =& $list;

                    // sorted
                    call_user_func_array('array_multisort', $args);

                    unset($columns);

                }
            }
            
            // apply sort direction
            if ( ! is_array($sort) && $sort == 'desc')
            {
                $list = array_values(array_reverse($list));
            }

            // slice before any filtering is applied
            // note: offset/limit can be used to 'slice' after filters are applied
            if ( ! is_null($slice))
            {
                $slice = array_map('intval', explode(',', $slice));
                if (isset($slice[1]))
                {
                    $list = array_slice($list, $slice[0], $slice[1]);
                }
                else
                {
                    $list = array_slice($list, $slice[0]);
                }
            }

            // compare column values against a statically tracked value, and *exclude* the row if the value matches
            if ($not_in)
            {
                $col_local = $col_tracked = $not_in;

                if (strstr($not_in, ':'))
                {
                    $not_in = explode(':', $not_in);

                    $col_local =  $not_in[0];
                    if (isset($not_in[1]))
                    {
                        $col_tracked = $not_in[1];
                    }
                }

                if (isset(self::$_cache['track'][$col_tracked]))
                { 
                    foreach($list as $key => $value)
                    {
                        if ( isset($value[$col_local]) && in_array($value[$col_local], self::$_cache['track'][$col_tracked]) )
                        {
                            unset($list[$key]);
                        }
                    }
                }
            }

            // compare column values against a statically tracked value, and *include* the row only if the value matches
            if ($in)
            {
                $new_list = array();
                $col_local = $col_tracked = $not_in;

                if (strstr($in, ':'))
                {
                    $in = explode(':', $in);

                    $col_local =  $in[0];
                    if (isset($in[1]))
                    {
                        $col_tracked = $in[1];
                    }
                }

                if (isset(self::$_cache['track'][$col_tracked]))
                { 
                    foreach($list as $key => $value)
                    {
                        if ( isset($value[$col_local]) && in_array($value[$col_local], self::$_cache['track'][$col_tracked]) )
                        {
                            $new_list[] = $value;
                        }
                    }
                }
                $list = $new_list;
            }
            
            // match/against: match the value of one of the list keys (specified by the against param) against a regex
            if ( ! is_null($match) && preg_match('/^#(.*)#$/', $match) && ! is_null($against))
            {
                $new_list = array();
                
                foreach($list as $key => $value)
                {
                    if ( isset($value[$against]) )
                    {
                        if ($this->_matches($match, $value[$against]))
                        {
                            // match found
                            $new_list[] = $value;
                        }
                    }
                }
                $list = $new_list;
            }

            // re-index array
            $list = array_values($list);
            
            // ensure we have unique rows?
            if ($unique !== NULL)
            {
                if ( FALSE === (bool) preg_match('/^(0|off|no|n)$/i', $unique))
                {
                    if ( FALSE === (bool) preg_match('/^(1|on|yes|y)$/i', $unique))
                    {
                        // unique across a single column
                        $unique_list = array();
                        $index = 0;
                        foreach($list as $key => $value)
                        {   
                            if ( isset($value[$unique]) )
                            {
                                $unique_list[$index] = array(
                                    $unique => $value[$unique]
                                );
                            }
                            ++$index;   
                        }

                        // make a unique list for the column
                        $unique_list = array_map('unserialize', array_unique(array_map('serialize', $unique_list)));

                        // restore original list values for the unique rows
                        $restored_list = array();
                        foreach($unique_list as $key => $value)
                        {
                            $restored_list[] = $list[$key];
                        }
                        $list = $restored_list;
                    }
                    else
                    {
                        // make a unique list
                        $list = array_map('unserialize', array_unique(array_map('serialize', $list)));
                    }
                }
            }
        }
        else
        {
            $list = array(); // make sure we always return an array
        }   
        return $list;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Retrieve {stash:var}{/stash:var} tag pairs and serialize
     *
     * @access private
     * @return void
     */
    private function _serialize_stash_tag_pairs()
    {
        $match   = ee()->TMPL->fetch_param('match', NULL); // regular expression to each list item against
        $against = ee()->TMPL->fetch_param('against', NULL); // array key to test $match against
        
        //  get the stash var pairs values
        $stash_vars = array(); 
     
        foreach(ee()->TMPL->var_pair as $key => $val)
        {   
            // valid variable pair?
            if (strncmp($key, 'stash:', 6) ==  0)
            {   
                // but does the pair exist for this row of the list?
                $starts_at = strpos(ee()->TMPL->tagdata, LD.$key.RD) + strlen(LD.$key.RD);
                $ends_at = strpos(ee()->TMPL->tagdata, LD."/".$key.RD, $starts_at);

                if (FALSE !== $starts_at && FALSE !== $ends_at)
                {   
                    // extract value between the pair
                    $tag_value = substr(ee()->TMPL->tagdata, $starts_at, $ends_at - $starts_at);

                    // don't save a string containing just white space, but be careful to preserve zeros
                    if ( $this->not_empty($tag_value) || $tag_value === '0')
                    {
                        $stash_vars[substr($key, 6)] = $tag_value;
                    }
                    else
                    {
                        // default key value: use a placeholder to represent a null/empty value
                        $stash_vars[substr($key, 6)] = $this->_list_null;
                    }
                }
                else
                {
                    // no tag pair found in this row - use a placeholder to represent a null/empty value
                    $stash_vars[substr($key, 6)] = $this->_list_null;
                }
            }
        }
        
        // match/against: optionally match against the value of one of the list keys, rather than the whole serialized variable
        if  ( ! is_null($match) 
            && preg_match('/^#(.*)#$/', $match) 
            && ! is_null($against) 
            && isset($stash_vars[$against])
            )
        {
            if ( ! $this->_matches($match, $stash_vars[$against]))
            {
                // match not found, end here
                ee()->TMPL->tagdata = '';
                return;
            }
            // disable match/against when setting the variable
            #unset(ee()->TMPL->tagparams['match']);
            #unset(ee()->TMPL->tagparams['against']);
        }
    
        // flatten the array into a string
        ee()->TMPL->tagdata = $this->_list_row_implode($stash_vars);
    }
    
    // ---------------------------------------------------------
    
    /**
     * @param array $array The array to implode
     * @return string The imploded array
     */ 
    private function _list_row_implode($array) 
    {
        if ( ! is_array( $array ) ) return $array;
        $string = array();
        foreach ( $array as $key => $val ) 
        {
            if ( is_array( $val ) )
            {
                $val = implode( ',', $val );
            }
            $string[] = "{$key}{$this->_list_row_glue}{$val}";
        }
        return implode( $this->_list_row_delimiter, $string );
    }
    
    // ---------------------------------------------------------
    
    /**
     * @param string $string The string to explode
     * @return array The exploded array
     */ 
    private function _list_row_explode($string) 
    {
        $array = explode($this->_list_row_delimiter, $string);
        
        $new_array = array();
        
        foreach ( $array as $key => $val ) 
        {
            $val = explode($this->_list_row_glue, $val);
            
            if (isset($val[1]))
            {
                // replace our null placeholder with an empty string
                if ($val[1] === $this->_list_null)
                {
                    $val[1] = '';
                }
                $new_array[$val[0]] = $val[1];
            }
        }   
        return $new_array;
    }


    // ---------------------------------------------------------
    
    /**
     * Flattens an array into a quasi-serialized format suitable for saving as a stash variable
     *
     * @param array $list The list array to flatten
     * @return string The imploded string
     */ 
    static public function flatten_list($array) 
    {
        $self = new self(); 
        $new_list = array();

        foreach($array as $value)
        {
            $new_list[] = $self->_list_row_implode($value);
        }

        return implode($self->_list_delimiter, $new_list);
    }
    
    // ---------------------------------------------------------

    /** 
     * Shuffle a stash list key
     *
     * @access public
     * @param array Multidimensional array to sort
     * @param string Array key to sort on
     * @param string Callback function
     * @return void
     */
    public function shuffle_list_key($arr, $key) 
    {
        $key_list = array();

        // shuffle the key array
        foreach($arr as $row)
        {
            if (isset($row[$key]))
            {
                $key_list[] = $row[$key];
            }
        }
        shuffle($key_list);

        // rebuld the list with the shuffled key
        $i = 0;
        foreach($arr as &$row)
        {
            if (isset($key_list[$i]))
            {
                $row[$key] = $key_list[$i];
            }
            ++$i;
        }
        unset($row);

        return $arr;
    }

    // ---------------------------------------------------------

    /** 
     * Normalize characters in a string (the dirty way)
     *
     * @access private
     * @param string 
     * @return string
     */
    private function _normalize($str) 
    {
        /* Character map courtesy of https://github.com/jbroadway/urlify */
        $char_map = array(

            /* German */
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'ẞ' => 'SS',

            /* latin */ 
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ă' => 'A', 'Æ' => 'AE', 
            'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I',
            'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' =>
            'O', 'Ő' => 'O', 'Ø' => 'O','Ș' => 'S','Ț' => 'T', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ű' => 'U',
            'Ý' => 'Y', 'Þ' => 'TH', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' =>
            'a', 'å' => 'a', 'ă' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' =>
            'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ő' => 'o', 'ø' => 'o', 'ș' => 's', 'ț' => 't', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u', 'ű' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',

            /* latin_symbols */  
            '©' => '(c)',

            /* Greek */
            'α' => 'a', 'β' => 'b', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'h', 'θ' => '8',
            'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => '3', 'ο' => 'o', 'π' => 'p',
            'ρ' => 'r', 'σ' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'x', 'ψ' => 'ps', 'ω' => 'w',
            'ά' => 'a', 'έ' => 'e', 'ί' => 'i', 'ό' => 'o', 'ύ' => 'y', 'ή' => 'h', 'ώ' => 'w', 'ς' => 's',
            'ϊ' => 'i', 'ΰ' => 'y', 'ϋ' => 'y', 'ΐ' => 'i',
            'Α' => 'A', 'Β' => 'B', 'Γ' => 'G', 'Δ' => 'D', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Θ' => '8',
            'Ι' => 'I', 'Κ' => 'K', 'Λ' => 'L', 'Μ' => 'M', 'Ν' => 'N', 'Ξ' => '3', 'Ο' => 'O', 'Π' => 'P',
            'Ρ' => 'R', 'Σ' => 'S', 'Τ' => 'T', 'Υ' => 'Y', 'Φ' => 'F', 'Χ' => 'X', 'Ψ' => 'PS', 'Ω' => 'W',
            'Ά' => 'A', 'Έ' => 'E', 'Ί' => 'I', 'Ό' => 'O', 'Ύ' => 'Y', 'Ή' => 'H', 'Ώ' => 'W', 'Ϊ' => 'I',
            'Ϋ' => 'Y',

            /* Turkish */
            'ş' => 's', 'Ş' => 'S', 'ı' => 'i', 'İ' => 'I', 'ç' => 'c', 'Ç' => 'C', 'ü' => 'u', 'Ü' => 'U',
            'ö' => 'o', 'Ö' => 'O', 'ğ' => 'g', 'Ğ' => 'G',

            /* Russian */
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'zh',
            'з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
            'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh',
            'З' => 'Z', 'И' => 'I', 'Й' => 'J', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sh', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu',
            'Я' => 'Ya',
            '№' => '',

            /* Ukrainian */
            'Є' => 'Ye', 'І' => 'I', 'Ї' => 'Yi', 'Ґ' => 'G', 'є' => 'ye', 'і' => 'i', 'ї' => 'yi', 'ґ' => 'g',

            /* Czech */
            'č' => 'c', 'ď' => 'd', 'ě' => 'e', 'ň' => 'n', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ů' => 'u',
            'ž' => 'z', 'Č' => 'C', 'Ď' => 'D', 'Ě' => 'E', 'Ň' => 'N', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T',
            'Ů' => 'U', 'Ž' => 'Z',

            /* Polish */
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z',
            'ż' => 'z', 'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'e', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S',
            'Ź' => 'Z', 'Ż' => 'Z',

            /* Romanian */
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'Ţ' => 'T', 'ţ' => 't',

            /* Latvian */
            'ā' => 'a', 'č' => 'c', 'ē' => 'e', 'ģ' => 'g', 'ī' => 'i', 'ķ' => 'k', 'ļ' => 'l', 'ņ' => 'n',
            'š' => 's', 'ū' => 'u', 'ž' => 'z', 'Ā' => 'A', 'Č' => 'C', 'Ē' => 'E', 'Ģ' => 'G', 'Ī' => 'i',
            'Ķ' => 'k', 'Ļ' => 'L', 'Ņ' => 'N', 'Š' => 'S', 'Ū' => 'u', 'Ž' => 'Z',

            /* Lithuanian */
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            'Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I', 'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z',

            /* Vietnamese */
            'Á' => 'A', 'À' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A', 'Ă' => 'A', 'Ắ' => 'A', 'Ằ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A', 'Â' => 'A', 'Ấ' => 'A', 'Ầ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
            'á' => 'a', 'à' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a', 'ă' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a', 'â' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'É' => 'E', 'È' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E', 'Ê' => 'E', 'Ế' => 'E', 'Ề' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
            'é' => 'e', 'è' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e', 'ê' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I', 'í' => 'i', 'ì' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O', 'Ô' => 'O', 'Ố' => 'O', 'Ồ' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O', 'Ơ' => 'O', 'Ớ' => 'O', 'Ờ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o', 'ô' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o', 'ơ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U', 'Ư' => 'U', 'Ứ' => 'U', 'Ừ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
            'ú' => 'u', 'ù' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u', 'ư' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'Ý' => 'Y', 'Ỳ' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y', 'ý' => 'y', 'ỳ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'Đ' => 'D', 'đ' => 'd',

            /* Arabic */
            'أ' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'g', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd',
            'ذ' => 'th', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't',
            'ظ' => 'th', 'ع' => 'aa', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'k', 'ك' => 'k', 'ل' => 'l', 'م' => 'm',
            'ن' => 'n', 'ه' => 'h', 'و' => 'o', 'ي' => 'y',

            /* Serbian */
            'ђ' => 'dj', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj', 'ћ' => 'c', 'џ' => 'dz', 'đ' => 'dj',
            'Ђ' => 'Dj', 'Ј' => 'j', 'Љ' => 'Lj', 'Њ' => 'Nj', 'Ћ' => 'C', 'Џ' => 'Dz', 'Đ' => 'Dj',

            /* Azerbaijani */
            'ç' => 'c', 'ə' => 'e', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'Ç' => 'C', 'Ə' => 'E', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U',
        );

        return strtr($str, $char_map);
    }

    // ---------------------------------------------------------

    /** 
     * Sort a multi-dimensional array by key
     *
     * @access public
     * @param array Multidimensional array to sort
     * @param string Array key to sort on
     * @param string Callback function
     * @return void
     */
    public function sort_by_key($arr, $key, $cmp='sort_by_integer') 
    {
        $this->_key2sort = $key;
        
        uasort($arr, array(__CLASS__, $cmp));
        return ($arr);
    }
    
    // ---------------------------------------------------------
    
    /** 
     * Sort callback function: sort by string
     *
     * @access protected
     * @param array
     * @param array
     */
    protected function sort_by_string($a, $b) 
    {
        return (@strcasecmp($a[$this->_key2sort], $b[$this->_key2sort]));
    }
    
    // --------------------------------------------------------- 
    
    /** 
     * Sort callback function: sort by integer
     *
     * @access protected
     * @param array
     * @param array
     */
    protected function sort_by_integer($a, $b)
    {
        if ($a[$this->_key2sort] == $b[$this->_key2sort]) 
        {
            return 0;
        }
        return ($a[$this->_key2sort] < $b[$this->_key2sort]) ? -1 : 1;
    }
    
    // --------------------------------------------------------- 
    
    /** 
     * get a fraction from a parameter value
     *
     * @access private
     * @param string
     * @param string
     * @param integer
     * @return integer
     */
    private function _parse_fraction($fraction, $offset=0, $total)
    {
        if (strstr($fraction, '/'))
        {
            $fraction = explode('/', $fraction);

            if (isset($fraction[1]) && $fraction[1] > 0)
            {
                $p = $fraction[1]; // the default number of partitions
                $start = 0; // index of the first partition
                $end = $fraction[0]; // index of the last partition

                 // do we have an offset?
                if ($offset)
                {
                    if (strstr($offset, '/'))
                    {
                        // we were passed a fraction
                        $offset = explode('/', $offset);
                    }
                    elseif( intval($offset) === $offset)
                    {
                        // we were passed an integer, convert to a fraction of the total
                        $offset = array($offset, $total);
                    }

                    if (isset($offset[1]) && $offset[1] > 0)
                    {
                        // do the denominators match?
                        if ($offset[1] !== $fraction[1])
                        {
                            // no, find the least common denominator for those numbers
                            $p = $this->_lcd(array($offset[1], $fraction[1]));

                            // multiply the numerators accordingly
                            $offset[0]   = $p / $offset[1] * $offset[0];
                            $fraction[0] = $p / $fraction[1] * $fraction[0];
                        }

                        // update indexes of start/end partitions
                        $start = $offset[0];
                        $end = $start + $fraction[0];
                    }
                    else
                    {
                        $offset = 0;
                    }
                }

                // partition a temporary list
                $partlen = floor($total / $p);
                $partrem = $total % $p;
                $partition = array();
                $mark = 0;
                $list  = array_fill(0, $total, 0);

                for($px = 0; $px < $p; $px++) 
                {
                    $incr = ($px < $partrem) ? $partlen + 1 : $partlen;
                    $partition[$px] = array_slice($list, $mark, $incr);
                    $mark += $incr;
                }

                unset($list);

                $i = $start;
                $index = 0;

                while ($i < $end) {
                    if (isset($partition[$i]))
                    {
                        $index += count($partition[$i]);
                    }
                    else break;
                    $i++;
                }
                return $index;
            }
        }
        else
        {
            return (int) $fraction;
        }
    }

    // ---------------------------------------------------------

    /** 
     * get the least common denominator for a given array of numbers
     *
     * @access private
     * @param array numbers to compare
     * @param integer the multiplication count
     * @return integer
     */
    private function _lcd($array, $x=1) 
    {            
        $mod_sum = 0;
        static $lcd = 0;
        
        for($int=1; $int < count($array); $int++) 
        {                
            $modulus[$int] = ($array[0]*$x) % ($array[$int]);
            $mod_sum = $mod_sum + $modulus[$int];            
        }
             
        if (!$mod_sum) 
        {
            $lcd = $array[0]*$x;
        } 
        else 
        {
            $this->_lcd($array, $x+1);
        }

        return $lcd;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Replace the current context in a variable name
     *
     * @access private
     * @param string    $name The variable name
     * @return string
     */
    private function _parse_context($name, $disable_query_str = FALSE)
    {   
        // replace '@:' with current context name
        if (strncmp($name, '@:', 2) == 0)
        {
            $name = str_replace('@', self::$context, $name);
        }

        // fetch the *unadulterated* URI of the current page
        if ( isset(self::$_cache['uri']))
        {
            $uri = self::$_cache['uri']; // retrieve from cache if we've done this before
        }
        else
        {
            $ee_uri = new EE_URI;

            // documented as a 'private' method, but not actually. Called in CI_Router so unlikely to ever be made private.
            $ee_uri->_fetch_uri_string(); 
            $ee_uri->_remove_url_suffix();
            $ee_uri->_explode_segments();

            // provide a fallback value for index pages
            $uri = $ee_uri->uri_string();
            $uri = empty($uri) ? ee()->stash_model->get_index_key() : $uri;

            self::$_cache['uri'] = $uri; // cache the value
        }

        // append query string?
        if ($this->include_query_str 
            && ! $disable_query_str
            && $query_str = ee()->input->server('QUERY_STRING')
        ){
            $uri = $uri . '?' . $query_str;
        }

        // replace '@URI:' with the current URI
        if (strncmp($name, '@URI:', 5) == 0)
        {
            $name = str_replace('@URI', $uri, $name);
        }

        // apply a global variable prefix, if set
        if ( $prefix = ee()->config->item('stash_var_prefix'))
        {
            if (strstr($name, ':'))
            {
                $name = str_replace(':', ':' . $prefix, $name);
            }
            else
            {
                $name = $prefix . $name;
            }
        }

        return $name;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Parse template data
     *
     * @access private
     * @param bool  $tags Parse plugin/module tags
     * @param bool  $vars Parse globals (inc. snippets), native stash vars and segments
     * @param bool  $conditionals Parse advanced conditionals
     * @param int   $depth Number of passes to make of the template tagdata
     * @return string
     */
    private function _parse_sub_template($tags = TRUE, $vars = TRUE, $conditionals = FALSE, $depth = 1, $nocache_id = FALSE)
    {   
        ee()->TMPL->log_item("Stash: processing inner tags");

        // optional prefix to use for nocache pairs
        if ($nocache_prefix = ee()->TMPL->fetch_param('prefix', FALSE))
        {
            // add to the array for optional removal at the end of template parsing
            if ( ! in_array($nocache_prefix, self::$_nocache_prefixes))
            {
                self::$_nocache_prefixes[] = $nocache_prefix;
            }
        }
        else
        {
            $nocache_prefix = 'stash';
        }

        // nocache tags
        if (FALSE === $nocache_id)
        {
            $this->nocache_id = ee()->functions->random();
        }

        $nocache = $nocache_prefix . $this->_nocache_suffix;
        $nocache_pattern = '/'.LD.$nocache.RD.'(.*)'.LD.'\/'.$nocache.RD.'/Usi';
            
        // save TMPL values for later
        $tagparams = ee()->TMPL->tagparams;
        $tagdata = ee()->TMPL->tagdata;
        
        // call the stash_fetch_template hook to prep nested stash embeds
        if (ee()->extensions->active_hook('stash_fetch_template') === TRUE && ! $this->_embed_nested)
        {
            // stash embed vars
            $embed_vars = (array) ee()->TMPL->fetch_param('embed_vars', array());      
            ee()->session->cache['stash'] = array_merge(ee()->session->cache['stash'], $embed_vars);
            
            // call the hook
            ee()->extensions->call('stash_fetch_template', array(
                'template_data'      => ee()->TMPL->tagdata
            ));
        
            // don't run again for this template
            $this->_embed_nested = TRUE;
        }
        
        // restore original TMPL values
        ee()->TMPL->tagparams = $tagparams;
        ee()->TMPL->tagdata = $tagdata;

        if (self::$_nocache)
        {
            // protect content inside {stash:nocache} tags, or {[prefix]:nocache} tags
            ee()->TMPL->tagdata = preg_replace_callback($nocache_pattern, array($this, '_placeholders'), ee()->TMPL->tagdata);
        }
        else
        {
            // remove extraneous {stash:nocache} tags, or {[prefix]:nocache} tags
            ee()->TMPL->tagdata = str_replace(array(LD.$nocache.RD, LD.'/'.$nocache.RD), '', ee()->TMPL->tagdata);
        }
    
        // parse variables  
        if ($vars)
        {   
            // note: each pass can expose more variables to be parsed after tag processing
            ee()->TMPL->tagdata = $this->_parse_template_vars(ee()->TMPL->tagdata);

            if (self::$_nocache)
            {
                // protect content inside {stash:nocache} tags that might have been exposed by parse_vars
                ee()->TMPL->tagdata = preg_replace_callback($nocache_pattern, array($this, '_placeholders'), ee()->TMPL->tagdata);
            }
            else
            {
                // remove extraneous {stash:nocache} tags, or {[prefix]:nocache} tags
                ee()->TMPL->tagdata = str_replace(array(LD.$nocache.RD, LD.'/'.$nocache.RD), '', ee()->TMPL->tagdata);
            }
        }

        // parse conditionals?
        if ($conditionals && strpos(ee()->TMPL->tagdata, LD.'if') !== FALSE)
        {   
            // prep {If var1 IN (var2)}../if] style conditionals
            if ($this->parse_if_in)
            {
                ee()->TMPL->tagdata = $this->_prep_in_conditionals(ee()->TMPL->tagdata);
            }

            // parse conditionals
            if (version_compare(APP_VER, '2.9', '<')) 
            {
                // pre EE 2.9, we can only parse "simple" segment and global conditionals on each pass, 
                // leaving "advanced" ones until after tag parsing has completed
                ee()->TMPL->tagdata = ee()->TMPL->parse_simple_segment_conditionals(ee()->TMPL->tagdata);
                ee()->TMPL->tagdata = ee()->TMPL->simple_conditionals(ee()->TMPL->tagdata, ee()->config->_global_vars);
            }
            else
            {   
                // with EE 2.9 and later we can parse conditionals when the variables referenced have a value ("when ready")

                // populate user variables
                $user_vars  = $this->_get_users_vars();
                $logged_in_user_cond = array();
                foreach ($user_vars as $val)
                {
                    if (isset(ee()->session->userdata[$val]) AND ($val == 'group_description' OR strval(ee()->session->userdata[$val]) != ''))
                    {
                        $logged_in_user_cond['logged_in_'.$val] = ee()->session->userdata[$val];
                    }
                }

                // Parse conditionals for known variables *without* converting unknown variables
                // used in if/else statements to false or 'n'
                ee()->TMPL->tagdata = ee()->functions->prep_conditionals(
                    ee()->TMPL->tagdata,
                    array_merge(
                        ee()->TMPL->segment_vars,
                        ee()->TMPL->template_route_vars,
                        ee()->TMPL->embed_vars,
                        $logged_in_user_cond,
                        ee()->config->_global_vars
                    )
                );
            }
        }
        
        // Remove any EE comments that might have been exposed before parsing tags
        if (strpos(ee()->TMPL->tagdata, '{!--') !== FALSE) 
        {
            ee()->TMPL->tagdata = preg_replace("/\{!--.*?--\}/s", '', ee()->TMPL->tagdata);
        }

        // parse tags, but check that there really are unparsed tags in the current shell   
        if ($tags && (strpos(ee()->TMPL->tagdata, LD.'exp:') !== FALSE))
        {
            // clone the template object
            $TMPL2 = ee()->TMPL;
            ee()->remove('TMPL');
            ee()->set('TMPL', new EE_Template());

            // copy object properties from original
            ee()->TMPL->start_microtime = $TMPL2->start_microtime;
            ee()->TMPL->template = $TMPL2->tagdata;
            ee()->TMPL->tag_data   = array();
            ee()->TMPL->var_single = array();
            ee()->TMPL->var_cond   = array();
            ee()->TMPL->var_pair   = array();
            ee()->TMPL->plugins = $TMPL2->plugins;
            ee()->TMPL->modules = $TMPL2->modules;
            ee()->TMPL->module_data = $TMPL2->module_data;

            // copy globals
            ee()->TMPL->segment_vars = $TMPL2->segment_vars;
            ee()->TMPL->embed_vars = $TMPL2->embed_vars;

            ee()->TMPL->template_route_vars = array();
            if ( isset($TMPL2->template_route_vars))
            {
                ee()->TMPL->template_route_vars = $TMPL2->template_route_vars;
            }

            ee()->TMPL->layout_conditionals = array();
            if ( isset($TMPL2->layout_conditionals))
            {
                ee()->TMPL->layout_conditionals = $TMPL2->layout_conditionals;
            }

            // parse tags
            ee()->TMPL->parse_tags();
            ee()->TMPL->process_tags();
            ee()->TMPL->loop_count = 0;
            
            $TMPL2->tagdata = ee()->TMPL->template;
            $TMPL2->log = array_merge($TMPL2->log, ee()->TMPL->log);

            ee()->remove('TMPL');
            ee()->set('TMPL', $TMPL2);
            unset($TMPL2);
        }
        else
        {
            $depth = 1;
        }

        // recursively parse?
        if ( $depth > 1)
        {
            $depth --;
            
            // the merry-go-round... parse the next shell of tags
            $this->_parse_sub_template($tags, $vars, $conditionals, $depth, $this->nocache_id);
        }
        else
        {
            // recursive parsing complete

            // parse advanced conditionals?
            if ($conditionals && strpos(ee()->TMPL->tagdata, LD.'if') !== FALSE)
            {
                // record if PHP is enabled for this template
                $parse_php = ee()->TMPL->parse_php;
                
                if ( ! isset(ee()->TMPL->layout_conditionals))
                {
                    ee()->TMPL->layout_conditionals = array();
                }

                // this will parse all remaining conditionals, with unknown variables used in if/else
                // statements being converted to false or 'n' so they are parsed safely
                ee()->TMPL->tagdata = ee()->TMPL->advanced_conditionals(ee()->TMPL->tagdata);
                
                // restore original parse_php flag for this template
                ee()->TMPL->parse_php = $parse_php;
            }   
                        
            // call the 'stash_post_parse' hook
            if (ee()->extensions->active_hook('stash_post_parse') === TRUE && $this->_embed_nested === TRUE)
            {    
                ee()->TMPL->tagdata = ee()->extensions->call(
                    'stash_post_parse',
                    ee()->TMPL->tagdata,
                    FALSE, 
                    $this->site_id
                );
            }

            // restore content inside {stash:nocache} tags
            // we must do this even if nocache has been disabled, since it may have been disabled after tags were escaped
            foreach ($this->_ph as $index => $val)
            {
                ee()->TMPL->tagdata = str_replace('[_'.__CLASS__.'_'.($index+1).'_'.$this->nocache_id.']', $val, ee()->TMPL->tagdata);
            }  

            // parse EE nocache placeholders {NOCACHE}
            ee()->TMPL->tagdata = ee()->TMPL->parse_nocache(ee()->TMPL->tagdata);   
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * Parse global vars inside a string
     *
     * @access private
     * @param string    $template String to parse
     * @return string
     */
    private function _parse_template_vars($template = '')
    {   
        // globals vars {name}
        if (count(ee()->config->_global_vars) > 0 && strpos($template, LD) !== FALSE)
        {
            foreach (ee()->config->_global_vars as $key => $val)
            {
                $template = str_replace(LD.$key.RD, $val, $template);
            }   
        }
        
        // stash vars {stash:var} 
        // note: due to the order we're doing this, global vars can themselves contain stash vars...
        if (count(ee()->session->cache['stash']) > 0 && strpos($template, LD.'stash:') !== FALSE)
        {   
            // We only want to replace single stash placeholder tags, 
            // NOT tag pairs such as {stash:var}whatever{/stash:var}
            $tag_vars = array();
            preg_match_all('#'.LD.'(stash:[a-z0-9\-_]+)'.RD.'(?!.+\1'.RD.')#ims', $template, $matches);

            if (isset($matches[1]))
            {
                 $tag_vars = array_flip($matches[1]);
            }
            
            foreach(ee()->session->cache['stash'] as $key => $val)
            {
                if (isset($tag_vars['stash:'.$key]))
                {
                    $template = str_replace(LD.'stash:'.$key.RD, $val, $template);
                }
            }
        }
        
        // user variables, in the form {logged_in_[variable]}
        if (strpos($template, LD.'logged_in_') !== FALSE)
        {  
            $user_vars  = $this->_get_users_vars();

            foreach ($user_vars as $val)
            {
                if (isset(ee()->session->userdata[$val]) AND ($val == 'group_description' OR strval(ee()->session->userdata[$val]) != ''))
                {
                    $template = str_replace(LD.'logged_in_'.$val.RD, ee()->session->userdata[$val], $template);
                }
            }  
        }    
        
        // Parse date format string "constants" 
        if (strpos($template, LD.'DATE_') !== FALSE)
        {   
            $date_constants = array('DATE_ATOM'     =>  '%Y-%m-%dT%H:%i:%s%Q',
                                    'DATE_COOKIE'   =>  '%l, %d-%M-%y %H:%i:%s UTC',
                                    'DATE_ISO8601'  =>  '%Y-%m-%dT%H:%i:%s%Q',
                                    'DATE_RFC822'   =>  '%D, %d %M %y %H:%i:%s %O',
                                    'DATE_RFC850'   =>  '%l, %d-%M-%y %H:%m:%i UTC',
                                    'DATE_RFC1036'  =>  '%D, %d %M %y %H:%i:%s %O',
                                    'DATE_RFC1123'  =>  '%D, %d %M %Y %H:%i:%s %O',
                                    'DATE_RFC2822'  =>  '%D, %d %M %Y %H:%i:%s %O',
                                    'DATE_RSS'      =>  '%D, %d %M %Y %H:%i:%s %O',
                                    'DATE_W3C'      =>  '%Y-%m-%dT%H:%i:%s%Q'
                                    );
            foreach ($date_constants as $key => $val)
            {
                $template = str_replace(LD.$key.RD, $val, $template);
            }
        }
        
        // Current time {current_time format="%Y %m %d %H:%i:%s"} - thanks @objectivehtml
        if (strpos($template, LD.'current_time') !== FALSE)
        {  
            if (preg_match_all("/".LD."current_time\s+format=([\"\'])([^\\1]*?)\\1".RD."/", $template, $matches))
            {             
                for ($j = 0; $j < count($matches[0]); $j++)
                {   
                    if (version_compare(APP_VER, '2.6', '>=')) 
                    {           
                        $template = str_replace($matches[0][$j], ee()->localize->format_date($matches[2][$j]), $template); 
                    }
                    else
                    {
                        $template = str_replace($matches[0][$j], ee()->localize->decode_date($matches[2][$j], ee()->localize->now), $template);
                    }   
                }
            }
        }
        
        // segment vars {segment_1} etc
        if (strpos($template, LD.'segment_' ) !== FALSE )
        {
            for ($i = 1; $i < 10; $i++)
            {
                $template = str_replace(LD.'segment_'.$i.RD, ee()->uri->segment($i), $template); 
            }
        }
        
        return $template;
    }
    
    // ---------------------------------------------------------
        
    /**
     * Final parsing of the stash variable before output to the template
     *
     * @access private
     * @param string $value the string to parse 
     * @param string $match A regular expression to match against
     * @param string $filter A regular expression to filter by
     * @param string $default fallback value
     * @return string
     */
    private function _parse_output($value = NULL, $match = NULL, $filter = NULL, $default = NULL)
    {
        // parse tags?
        if ( ($this->parse_tags || $this->parse_vars || $this->parse_conditionals) && ! $this->parse_complete)
        {   
            // do parsing
            ee()->TMPL->tagdata = $value;
            $this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
            $value = ee()->TMPL->tagdata;
            unset(ee()->TMPL->tagdata);
        }
    
        // regex match
        if ( $match !== NULL && $value !== NULL )
        {   
            $is_match = $this->_matches($match, $value);

            if ( ! $is_match )
            {
                $value = $default;
            } 
        }
        
        // regex filter
        if ( $filter !== NULL && $value !== NULL)
        {
            preg_match($filter, $value, $found);
            if (isset($found[1]))
            {
                $value = $found[1];
            }
        }
        // apply string manipulations
        $value = $this->_clean_string($value);
        
        return $value;
    }
    
    // ---------------------------------------------------------
    
    /**
     * String manipulations
     *
     * @access private
     * @param string $value the string to parse 
     * @return string
     */
    private function _clean_string($value = NULL)
    {
        // register parameters
        $trim = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('trim'));
        $strip_tags = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('strip_tags'));   
        $strip_curly_braces = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('strip_curly_braces'));   
        $strip_unparsed = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('strip_unparsed'));
        $compress = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('compress'));
        $backspace = (int) ee()->TMPL->fetch_param('backspace', 0);
        $strip_vars = ee()->TMPL->fetch_param('strip', FALSE);
        
        // support legacy parameter name
        if ( ! $strip_unparsed)
        {
            $strip_unparsed = (bool) preg_match('/1|on|yes|y/i', ee()->TMPL->fetch_param('remove_unparsed_vars'));
        }
        
        // trim?
        if ($trim)
        {
            $value  = str_replace( array("\t", "\n", "\r", "\0", "\x0B"), '', trim($value));
        }

        // remove whitespace between tags which are separated by line returns?
        if ($compress)
        {   
            // remove spaces between tags
            $value  = preg_replace('~>\s*\n\s*<~', '><', $value);

            // double spaces, leading and trailing spaces
            $value  = trim(preg_replace('/\s\s+/', ' ', $value));
        }

        // strip tags?
        if ($strip_tags)
        {
            $value = strip_tags($value);
        }

        // strip curly braces?
        if ($strip_curly_braces)
        {
            $value = str_replace(array(LD, RD), '', $value);
        }
    
        // backspace?
        if ($backspace)
        {
            // backspace can break unparsed conditionals and tags, so lets check for them
            $remove_from_end = substr($value, -$backspace);

            if (strrpos($remove_from_end, RD) !== false)
            {
                // unparsed var or tag within the backspace range, trim end as far as we safely can
                $value = substr($value, 0, strrpos($value, RD)+1);
            }
            else
            {
                $value = substr($value, 0, -$backspace);
            }
        }
    
        // xss clean?
        if ($this->xss_clean)
        {
            $value = ee()->security->xss_clean($value);
        }
        
        // remove leftover placeholder variables {var} (leave stash: vars untouched)
        if ($strip_unparsed)
        {
            $value = preg_replace('/\{\/?(?!\/?stash)[a-zA-Z0-9_\-:]+\}/', '', $value);
        }

        // cleanup specified single and pair variable placeholders
        if ($strip_vars)
        {
            $strip_vars = explode("|", $strip_vars);

            foreach($strip_vars as $var)
            {
                $value = str_replace(array(LD.$var.RD, LD.'/'.$var.RD), '', $value);
            }
        }

        return $value;
    }
    
    // ---------------------------------------------------------
    
    /**
     * Run a Stash module tag with a known set of parameters
     *
     * @access private
     * @param string $method the public Stash method to call    
     * @param array $params the tag parameters to use
     * @return string
     */
    private function _run_tag($method, $params = array())
    {
        // make a copy of the original parameters
        $original_params = ee()->TMPL->tagparams;
        
        // array of permitted parameters
        $allowed_params = array_flip($params);
        
        // set permitted params for use
        foreach($allowed_params as $key => &$value)
        {
            if ( isset(ee()->TMPL->tagparams[$key]))
            {
                $value = ee()->TMPL->tagparams[$key];
            }
            else
            {
                unset($allowed_params[$key]);
            }
        }
        
        // overwrite template params with our safe set
        ee()->TMPL->tagparams = $allowed_params;
        
        // run the tag if it is public
        if (method_exists($this, $method))
        {
            $reflection = new ReflectionMethod($this, $method);
            if ( ! $reflection->isPublic()) 
            {
                throw new RuntimeException("The called method is not public.");
            }
            $out = $this->$method();
        }
        
        // restore original parameters
        ee()->TMPL->tagparams = $original_params;
        
        unset($original_params);
        
        return $out;
    }   
    
    // ---------------------------------------------------------
    
    /** 
     * Replaces nested tag content with placeholders
     *
     * @access private
     * @param array $matches
     * @return string
     */ 
    private function _placeholders($matches)
    {
        $this->_ph[] = $matches[1];
        return '[_'.__CLASS__.'_'.count($this->_ph).'_'.$this->nocache_id.']';
    }
    
    // ---------------------------------------------------------
    
    /**
     * Delay processing a tag until template_post_parse hook
     * 
     * @access private
     * @param String    Method name (e.g. display, link or embed)
     * @return Mixed    TRUE if delay, FALSE if not
     */
    private function _post_parse($method)
    {
        // base our needle off the calling tag
        // add a random number to prevent EE caching the tag, if it is used more than once
        $placeholder = md5(ee()->TMPL->tagproper) . rand();    
              
        if ( ! isset(ee()->session->cache['stash']['__template_post_parse__']))
        {
            ee()->session->cache['stash']['__template_post_parse__'] = array();
        }
        
        if ($this->process == 'end')
        {
            // postpone until end of tag processing
            $cache =& ee()->session->cache['stash']['__template_post_parse__'];
        }
        else
        {
            // unknown or impossible post-process stage
            ee()->output->show_user_error('general', sprintf(ee()->lang->line('unknown_post_process'), ee()->TMPL->tagproper, $this->process));
            return;
        }
            
        ee()->TMPL->log_item("Stash: this tag will be post-processed on {$this->process}: {ee()->TMPL->tagproper}");

        $cache[$placeholder] = array(
            'method'    => $method,
            'tagproper' => ee()->TMPL->tagproper,
            'tagparams' => ee()->TMPL->tagparams,
            'tagdata'   => ee()->TMPL->tagdata,
            'priority'  => $this->priority
        );
            
        // return needle so we can find it later
        return LD.$placeholder.RD;

    }
    
    // ---------------------------------------------------------
    
    /**
     * Prep {if var IN (array)} conditionals
     *
     * Used with the permission of Lodewijk Schutte
     * http://gotolow.com/addons/low-search
     *
     * @access private
     * @param string $tagdata
     * @return String   
     */ 
    private function _prep_in_conditionals($tagdata = '')
    {
        if (preg_match_all('#'.LD.'if (([\w\-_]+)|((\'|")(.+)\\4)) (NOT)?\s?IN \((.*?)\)'.RD.'#', $tagdata, $matches))
        {
            foreach ($matches[0] as $key => $match)
            {
                $left    = $matches[1][$key];
                $operand = $matches[6][$key] ? '!=' : '==';
                $andor   = $matches[6][$key] ? ' AND ' : ' OR ';
                $items   = preg_replace('/(&(amp;)?)+/', '|', $matches[7][$key]);
                $cond    = array();
                foreach (explode('|', $items) as $right)
                {
                    $tmpl   = preg_match('#^(\'|").+\\1$#', $right) ? '%s %s %s' : '%s %s "%s"';
                    $cond[] = sprintf($tmpl, $left, $operand, $right);
                }

                // replace {if var IN (1|2|3)} with {if var == '1' OR var == '2' OR var == '3'}
                $tagdata = str_replace($match, LD.'if '.implode($andor, $cond).RD, $tagdata);
            }
        }
        return $tagdata;
    }

    // ---------------------------------------------------------
    
    /**
     * prep a prefixed no_results block in current template tagdata
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function _prep_no_results($prefix)
    {
        if (strpos(ee()->TMPL->tagdata, 'if '.$prefix.':no_results') !== FALSE 
                && preg_match("/".LD."if ".$prefix.":no_results".RD."(.*?)".LD.'\/'."if".RD."/s", ee()->TMPL->tagdata, $match)) 
        {
            if (stristr($match[1], LD.'if'))
            {
                $match[0] = ee()->functions->full_tag($match[0], $block, LD.'if', LD.'\/'."if".RD);
            }
        
            $no_results = substr($match[0], strlen(LD."if ".$prefix.":no_results".RD), -strlen(LD.'/'."if".RD));
            $no_results_block = $match[0];
            
            // remove {if prefix:no_results}..{/if} block from template
            ee()->TMPL->tagdata = str_replace($no_results_block, '', ee()->TMPL->tagdata);
            
            // set no_result variable in Template class
            ee()->TMPL->no_results = $no_results;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * parse and return no_results content
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function _no_results()
    {
        if ( ! empty(ee()->TMPL->no_results))
        {
            // parse the no_results block if it's got content
            ee()->TMPL->no_results = $this->_parse_output(ee()->TMPL->no_results);
        }
        return ee()->TMPL->no_results();
    }

    // ---------------------------------------------------------
    
    /**
     * remove a given prefix from common variables in the template tagdata
     * 
     * @access private
     * @param string $prefix
     * @param string $template
     * @return String   
     */ 
    private function _un_prefix($prefix, $template)
    {
        // remove prefix
        $common = array('count', 'absolute_count', 'total_results', 'absolute_results', 'switch', 'no_results');

        foreach($common as $muck)
        {
             $template = str_replace($prefix.':'.$muck, $muck,  $template);
        }

        return $template;
    }

    // ---------------------------------------------------------
    
    /**
     * set individual parse parameters if parse="yes"
     * 
     * @access public
     * @param string $prefix
     * @return String   
     */ 
    function set_parse_params()
    {
        $parse = ee()->TMPL->fetch_param('parse', NULL);

        if ( NULL !== $parse)
        {
            if ( (bool) preg_match('/1|on|yes|y/i', $parse))
            {
                // parse="yes"
                ee()->TMPL->tagparams['parse_tags']          = 'yes';
                ee()->TMPL->tagparams['parse_vars']          = 'yes';
                ee()->TMPL->tagparams['parse_conditionals']  = 'yes';
            } 
            elseif ( (bool) preg_match('/^(0|off|no|n)$/i', $parse))    
            {   
                // parse="no"
                ee()->TMPL->tagparams['parse_tags']          = 'no';
                ee()->TMPL->tagparams['parse_vars']          = 'no';
                ee()->TMPL->tagparams['parse_conditionals']  = 'no';
            }
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * API: call a Stash method directly
     * 
     * @access public
     * @param string $method
     * @param mixed $params variable name or an array of parameters
     * @param string $type
     * @param string $scope
     * @param string $value
     * @return void 
     */ 
    private function _api_call($method, $params, $type='variable', $scope='user', $value=NULL)
    {
        // make sure we have a Template object to work with, in case Stash is being invoked outside of a template
        if ( ! class_exists('EE_Template'))
        {
            $this->_load_EE_TMPL();
        }
      
        // make a copy of the current tagparams and tagdata for later
        $original_tagparams = array();
        $original_tagdata = FALSE;

        if ( isset(ee()->TMPL->tagparams))
        {
            $original_tagparams = ee()->TMPL->tagparams;
        }
        if ( isset(ee()->TMPL->tagdata))
        {
            $original_tagdata = ee()->TMPL->tagdata;
        }

        // make sure we have a slate to work with
        ee()->TMPL->tagparams = array();
        ee()->TMPL->tagdata = FALSE;
        
        if ( is_array($params))
        {
            ee()->TMPL->tagparams = $params;
        }
        else
        {
            ee()->TMPL->tagparams['name']    = $params;
            ee()->TMPL->tagparams['type']    = $type;
            ee()->TMPL->tagparams['scope']   = $scope;
        }
        
        if ( ! is_null($value))
        {
            ee()->TMPL->tagdata = $value;
        }
        
        $this->init(); // re-initilize Stash 
        $result = $this->{$method}();

        // restore original template params and tagdata
        ee()->TMPL->tagparams = $original_tagparams;
        ee()->TMPL->tagdata = $original_tagdata;

        return $result;
    }

    // ---------------------------------------------------------
    
    /**
     * API: call a Stash method statically (DEPRECATED, PHP <5.6 only)
     * 
     * @access public
     * @param string $method
     * @param mixed $params variable name or an array of parameters
     * @param string $type
     * @param string $scope
     * @param string $value
     * @return void 
     */ 
    private function _api_static_call($method, $params, $type='variable', $scope='user', $value=NULL)
    {
        // make sure we have a Template object to work with, in case Stash is being invoked outside of a template
        if ( ! class_exists('EE_Template'))
        {
            self::_load_EE_TMPL();
        }
      
        // make a copy of the current tagparams and tagdata for later
        $original_tagparams = array();
        $original_tagdata = FALSE;

        if ( isset(ee()->TMPL->tagparams))
        {
            $original_tagparams = ee()->TMPL->tagparams;
        }
        if ( isset(ee()->TMPL->tagdata))
        {
            $original_tagdata = ee()->TMPL->tagdata;
        }

        // make sure we have a slate to work with
        ee()->TMPL->tagparams = array();
        ee()->TMPL->tagdata = FALSE;
        
        if ( is_array($params))
        {
            ee()->TMPL->tagparams = $params;
        }
        else
        {
            ee()->TMPL->tagparams['name']    = $params;
            ee()->TMPL->tagparams['type']    = $type;
            ee()->TMPL->tagparams['scope']   = $scope;
        }
        
        if ( ! is_null($value))
        {
            ee()->TMPL->tagdata = $value;
        }
        
        // as this function is called statically, we need to get a Stash object instance and run the requested method
        $self = new self(); 
        $result = $self->{$method}();

        // restore original template params and tagdata
        ee()->TMPL->tagparams = $original_tagparams;
        ee()->TMPL->tagdata = $original_tagdata;

        return $result;
    }
    
    // ---------------------------------------------------------
    
    /** 
     * Check if the user agent is a bot
     *
     * @access public
     * @return void
     */ 
    private function _is_bot() 
    {   
        $bot_test = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : (php_sapi_name() === 'cli' ? 'cli' : 'other');
        $is_bot = FALSE;
    
        if (empty($bot_test)) 
        {
            $is_bot = TRUE; // no UA string, assume it's a bot
        }
        else
        {
            // Most active *legitimate* bots will contain one of these strings in the UA
            $bot_list = ee()->config->item('stash_bots') ? 
                        ee()->config->item('stash_bots') : 
                        array('bot', 'crawl', 'spider', 'archive', 'search', 'java', 'yahoo', 'teoma');
    
            foreach($bot_list as $bot) 
            {
                if(strpos($bot_test, $bot) !== FALSE) 
                {
                    $is_bot = TRUE;
                    break; // stop right away to save processing
                }
            }
        }
        return $is_bot;
    }

    /**
     * get the boolean value of a config item, with the desired fallback value
     * 
     * @access private
     * @param string $item config key
     * @param boolean $default default value returned if config item doesn't exist
     * @return boolean 
     */ 
    private function _get_boolean_config_item($item, $default = TRUE)
    { 
        if ( isset(ee()->config->config[$item])) 
        {
            if (ee()->config->config[$item] === FALSE)
            {
                return FALSE;
            }
            else
            {
                return TRUE;
            }
        }
        else
        {
            return $default;
        }
    }
    
    // ---------------------------------------------------------
    
    /**
     * set the stash cookie
     * 
     * @access private
     * @param string $unique_id the session ID
     * @param integer $expire cookie duration in seconds
     * @return void 
     */ 
    private function _set_stash_cookie($unique_id)
    { 
        $cookie_data = json_encode(array(
           'id' => $unique_id,
           'dt' => ee()->localize->now
        ));

        if (version_compare(APP_VER, '2.8', '>=')) 
        { 
            ee()->input->set_cookie($this->stash_cookie, $cookie_data, $this->stash_cookie_expire);
        }
        else
        {
            ee()->functions->set_cookie($this->stash_cookie, $cookie_data, $this->stash_cookie_expire);
        }  
    }
    
    /**
     * get the stash cookie
     * 
     * @access private
     * @return boolean/array    
     */ 
    private function _get_stash_cookie()
    { 
        $cookie_data = @json_decode(ee()->input->cookie($this->stash_cookie), TRUE);
        
        if ($cookie_data !== FALSE)
        {
            // make sure the cookie hasn't been monkeyed with
            if ( isset($cookie_data['id']) && isset($cookie_data['dt']))
            {
                // make sure we have a valid 40-character SHA-1 hash
                if ( (bool) preg_match('/^[0-9a-f]{40}$/i', $cookie_data['id']) )
                {
                    // make sure we have a valid timestamp
                    if ( ((int) $cookie_data['dt'] === $cookie_data['dt']) 
                        && ($cookie_data['dt'] <= PHP_INT_MAX)
                        && ($cookie_data['dt'] >= ~PHP_INT_MAX) )
                    {
                        return $cookie_data;
                    }
                }
            }
        }
        return FALSE;
    }

    /**
     * return the standard set of user variables
     * 
     * @access private
     * @return array    
     */ 
    private function _get_users_vars()
    {
        return array(
            'member_id', 'group_id', 'group_description', 
            'group_title', 'username', 'screen_name', 
            'email', 'ip_address', 'location', 'total_entries', 
            'total_comments', 'private_messages', 'total_forum_posts', 
            'total_forum_topics', 'total_forum_replies'
        );
    }

}

/* End of file mod.stash.php */
/* Location: ./system/expressionengine/third_party/stash/mod.stash.php */
